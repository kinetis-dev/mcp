<?php

declare(strict_types=1);

namespace Kinetis\Mcp;

use Kinetis\McpProtocol\Exception\JsonRpcException;
use Kinetis\McpProtocol\McpApplication;
use Kinetis\McpProtocol\ProgressEmitter;
use Kinetis\McpProtocol\ResourceDescription;
use Kinetis\McpProtocol\ResourceResult;
use Kinetis\McpProtocol\ToolDescription;
use Kinetis\McpProtocol\ToolResult;
use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\JsonObject;
use Kinetis\Validation\JsonTree;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use stdClass;
use Throwable;

/**
 * The whole of what Kinetis adds to the shared protocol server: this
 * package's attribute registry and validating dispatcher, published as the
 * tools and resources that server serves.
 *
 * $context is the per-message container the caller created — a
 * RequestScope from {@see ScopedMessageHandler} over stdio, the request's
 * own scope over HTTP. It is passed on to McpDispatcher so a tool resolves
 * its controller and dependencies from the scope of its own call; anything
 * that is not a container is ignored, and the dispatcher's own container
 * is used instead.
 *
 * A tool's return value is JSON-encoded as a successful text result,
 * except a ToolResult, which is returned as the tool built it: that is how
 * a tool reports a deliberate refusal, with text it has already made safe
 * for the client.
 *
 * A tool or resource *executing* and failing is reported as a normal MCP
 * result with `isError: true` or, for a read, as a generic protocol error.
 * A failed validation keeps its real violations, which is the argument
 * feedback an agent retries on. Any other exception gets a fixed message and
 * the real exception goes to the logger — its text can carry SQL, a path
 * or a credential, the same client-facing/logged split
 * ExceptionHandlerMiddleware applies to an HTTP 500.
 */
final readonly class KinetisMcpApplication implements McpApplication
{
    public function __construct(
        private McpRegistry $registry,
        private McpDispatcher $dispatcher,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * @return list<ToolDescription>
     */
    #[\Override]
    public function tools(): array
    {
        return array_map(
            static fn (ToolDefinition $tool): ToolDescription => new ToolDescription(
                $tool->name,
                $tool->description,
                $tool->inputSchema,
            ),
            $this->registry->tools(),
        );
    }

    /**
     * @return list<ResourceDescription>
     */
    #[\Override]
    public function resources(): array
    {
        return array_map(
            static fn (ResourceDefinition $resource): ResourceDescription => new ResourceDescription(
                $resource->uri,
                $resource->name,
                $resource->description,
                $resource->mimeType,
            ),
            $this->registry->resources(),
        );
    }

    #[\Override]
    public function callTool(
        string $name,
        stdClass $arguments,
        ProgressEmitter $progress,
        ?object $context,
    ): ToolResult {
        $tool = $this->registry->findTool($name);

        if ($tool === null) {
            // The server resolves $name against tools() before calling,
            // so this is only reachable if a registry changed underneath
            // one message. Answered in the server's own vocabulary rather
            // than left to become a generic internal error.
            throw JsonRpcException::invalidParams("Unknown tool: \"{$name}\".");
        }

        try {
            $result = $this->dispatcher->callTool(
                $tool,
                $this->hydrationArguments($arguments),
                new ProgressReporter($progress),
                self::container($context),
            );

            return $result instanceof ToolResult
                ? $result
                : ToolResult::text(json_encode($result, JSON_THROW_ON_ERROR));
        } catch (ValidationException $e) {
            // The failure's own violations: the same ordered path/code/
            // message/parameters objects the HTTP renderer puts in its
            // problem document, not that document itself, which describes
            // a response an MCP client never receives. Substituting
            // invalid UTF-8 rather than throwing on it keeps a constraint
            // that quotes raw client bytes back from turning argument
            // feedback into a transport failure carrying no feedback.
            return ToolResult::error(json_encode(
                ['errors' => $e->violations],
                JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
            ));
        } catch (Throwable $e) {
            $this->logSafely('Tool "{tool}" threw: {message}', [
                'tool' => $name,
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return ToolResult::error('Tool execution failed.');
        }
    }

    #[\Override]
    public function readResource(string $uri, ?object $context): ResourceResult
    {
        $resource = $this->registry->findResource($uri);

        if ($resource === null) {
            throw JsonRpcException::resourceNotFound($uri);
        }

        try {
            $content = $this->dispatcher->readResource($resource, self::container($context));

            return new ResourceResult(
                $resource->uri,
                $resource->mimeType,
                is_string($content) ? $content : json_encode($content, JSON_THROW_ON_ERROR),
            );
        } catch (Throwable $e) {
            $this->logSafely('Resource "{uri}" threw: {message}', [
                'uri' => $uri,
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            throw JsonRpcException::internalError();
        }
    }

    /**
     * The arguments object as Hydrator needs it: a plain array of members,
     * with every nested JSON object still marked as one.
     *
     * JsonTree::convert() is what preserves that. A JSON object whose keys
     * happen to look sequential (`{"0":"a","1":"b"}`) flattens to exactly
     * the shape a real JSON array flattens to, and `array_is_list()`
     * cannot tell them apart afterwards, so Hydrator's own array/iterable
     * type-mismatch check would accept an object where a list is declared.
     * Converting from the raw tree keeps that distinction at every depth;
     * only the top-level marker is unwrapped here, because the dispatcher
     * takes the members themselves.
     *
     * @return array<string, mixed>
     */
    private function hydrationArguments(stdClass $arguments): array
    {
        $converted = JsonTree::convert($arguments);

        return $converted instanceof JsonObject ? $converted->toArray() : [];
    }

    private static function container(?object $context): ?ContainerInterface
    {
        return $context instanceof ContainerInterface ? $context : null;
    }

    /**
     * Logging a caught failure must never become a second failure: PSR-3
     * places no no-throw obligation on an implementation, and one bad
     * message must not crash a long-running stdio process or replace the
     * result already decided on. A logger exception is discarded rather
     * than reported anywhere else, because this already is the terminal
     * boundary.
     *
     * @param array<string, mixed> $context
     */
    private function logSafely(string $message, array $context): void
    {
        try {
            $this->logger->error($message, $context);
        } catch (Throwable) {
            // Discarded deliberately — see this method's own docblock.
        }
    }
}
