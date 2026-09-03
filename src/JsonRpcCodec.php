<?php

declare(strict_types=1);

namespace Kinetis\Mcp;

use Kinetis\Mcp\Exception\JsonRpcException;
use stdClass;

/**
 * The one JSON-RPC 2.0 envelope decode/structural-validation path shared by
 * every entry point into this server — Http\McpController, Transport\
 * StdioTransport, and McpServer::handle()/preflight() (for a message built
 * directly by an embedder rather than received from either transport).
 * Each of those feeds a message through here before McpServer ever
 * dispatches on `method`, so a malformed envelope gets identical JSON-RPC
 * code/id semantics regardless of which one received it.
 *
 * decode() is the raw-bytes entry point: it decodes with `json_decode()`'s
 * default *object* mode (a JSON object becomes `stdClass`, a JSON array
 * becomes a plain PHP array — never both the same PHP shape) and keeps
 * that fidelity in the message it returns, rather than flattening eagerly.
 * That matters because `{}` and `[]` are genuinely distinct on the wire —
 * an empty JSON object is a valid `params`/`_meta`/`arguments` value, an
 * empty JSON array is not — and PHP's associative-array decode mode
 * collapses both to the identical `[]`, indistinguishable regardless of
 * emptiness. Working with the raw `stdClass`/array tree for as long as
 * validation needs it (McpServer::preflight() reads the same
 * not-yet-flattened `params` this class hands back) is what preserves
 * that distinction all the way through; only once every shape has been
 * validated does anything call toArrayDeep() to produce the plain arrays
 * every other part of this codebase already expects.
 *
 * A caller building a message directly — a test, or any other embedder
 * bypassing decode() entirely — has no way to write that same distinction
 * in a bare PHP array literal (`[]` is the only spelling for both an empty
 * list and "nothing to put in an object"), so isStrictJsonObject() also
 * accepts JsonObject: an explicit, unambiguous "treat this as an object"
 * marker for exactly that case.
 */
final class JsonRpcCodec
{
    // Never instantiated — every method here is static.
    private function __construct() {}

    /**
     * @return array{message: array<string, mixed>}|array{errorResponse: array<string, mixed>}
     */
    public static function decode(string $raw): array
    {
        $decoded = json_decode($raw);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['errorResponse' => self::errorEnvelope(null, JsonRpcException::parseError())];
        }

        if (!$decoded instanceof stdClass) {
            // Valid JSON, but not an object at all — a bare scalar/bool/
            // null, or a top-level JSON array. Object-mode decode already
            // makes a JSON array a plain PHP array rather than stdClass,
            // so this one check covers a batch request too — batching is
            // not defined for the 2026-07-28 revision this server
            // implements, and there is no id to detect here regardless,
            // so this can never be mistaken for a notification.
            return ['errorResponse' => self::errorEnvelope(null, JsonRpcException::invalidRequest())];
        }

        $error = self::validateMessage($decoded);

        if ($error !== null) {
            return ['errorResponse' => $error];
        }

        return ['message' => [
            'jsonrpc' => $decoded->jsonrpc,
            'method' => $decoded->method,
            ...(property_exists($decoded, 'id') ? ['id' => $decoded->id] : []),
            // Deliberately not flattened — see this class's own docblock.
            // McpServer::preflight() is what finally converts it, once
            // every nested shape it cares about has been validated.
            ...(property_exists($decoded, 'params') ? ['params' => $decoded->params] : []),
        ]];
    }

    /**
     * Structural validation only — never dispatches, never touches
     * `method`'s meaning, just whether the envelope is well-formed enough
     * to hand to McpServer::preflight()/handle(). Returns null when valid,
     * or a complete JSON-RPC error envelope when not. $message may be the
     * raw `stdClass` decode() itself works with, or a plain array — every
     * read goes through objectGet()/objectHas(), which accept either.
     *
     * Per JSON-RPC 2.0, only a *structurally valid* request without `id`
     * is a notification and suppresses its response — an invalid message
     * missing `id` still gets an error response, with `id: null` since
     * there was nothing valid to echo back.
     *
     * @return array<string, mixed>|null
     */
    public static function validateMessage(mixed $message): ?array
    {
        $idPresent = self::objectHas($message, 'id');
        $idValue = self::objectGet($message, 'id');
        $idValid = !$idPresent || self::isValidId($idValue);
        $safeId = $idPresent && $idValid ? $idValue : null;

        if (self::objectGet($message, 'jsonrpc') !== '2.0') {
            return self::errorEnvelope($safeId, JsonRpcException::invalidRequest());
        }

        if (!is_string(self::objectGet($message, 'method'))) {
            return self::errorEnvelope($safeId, JsonRpcException::invalidRequest());
        }

        if ($idPresent && !$idValid) {
            return self::errorEnvelope(null, JsonRpcException::invalidRequest());
        }

        if (self::objectHas($message, 'params') && !self::isStrictJsonObject(self::objectGet($message, 'params'))) {
            return self::errorEnvelope(
                $safeId,
                JsonRpcException::invalidParams('The "params" member must be an object.'),
            );
        }

        return null;
    }

    /**
     * The JSON-RPC 2.0 id domain this server supports: a string, an
     * integer, or null. Booleans, arrays/objects, and floats (including a
     * precision-losing number, and a whole-number float like `5.0` — this
     * server does not attempt to distinguish that from a genuinely
     * fractional one) are all rejected rather than silently coerced.
     */
    public static function isValidId(mixed $value): bool
    {
        return is_string($value) || is_int($value) || $value === null;
    }

    /**
     * True only for a value that unambiguously represents a JSON object:
     * a real `stdClass` (however it arrived — decode()'s own object-mode
     * parse, or a nested property read off one), a JsonObject, or a
     * non-empty, non-list PHP array (unambiguous on its own, since a real
     * string key is present). A bare `[]` is *not* accepted — see this
     * class's own docblock for why, and JsonObject for the one way to
     * represent a genuinely empty object without going through decode().
     */
    public static function isStrictJsonObject(mixed $value): bool
    {
        if ($value instanceof stdClass || $value instanceof JsonObject) {
            return true;
        }

        return is_array($value) && $value !== [] && !array_is_list($value);
    }

    /**
     * Reads $key off $node — a `stdClass`, a JsonObject, or a plain
     * array, the same three shapes isStrictJsonObject() accepts — or
     * null when $node is none of those or doesn't carry $key at all.
     */
    public static function objectGet(mixed $node, string $key): mixed
    {
        if ($node instanceof JsonObject) {
            $node = $node->toArray();
        }

        if ($node instanceof stdClass) {
            return $node->{$key} ?? null;
        }

        return is_array($node) ? ($node[$key] ?? null) : null;
    }

    /**
     * True when $node genuinely carries $key — property_exists()/
     * array_key_exists() semantics, not just a non-null check, so a
     * present-but-null value is still detected as present (and, per
     * isStrictJsonObject(), still rejected wherever an object is
     * required — an explicit null is not the same as omitting the key).
     */
    public static function objectHas(mixed $node, string $key): bool
    {
        if ($node instanceof JsonObject) {
            $node = $node->toArray();
        }

        if ($node instanceof stdClass) {
            return property_exists($node, $key);
        }

        return is_array($node) && array_key_exists($key, $node);
    }

    /**
     * Deeply converts a validated `stdClass`/JsonObject/array tree into
     * plain arrays — the shape every consumer downstream of validation
     * (McpDispatcher, Hydrator) already expects. Not itself a validation
     * step: only ever called once every node it touches has already
     * passed shape validation, so there is nothing left to reject here.
     */
    public static function toArrayDeep(mixed $node): mixed
    {
        if ($node instanceof JsonObject) {
            $node = $node->toArray();
        }

        if ($node instanceof stdClass) {
            $node = (array) $node;
        }

        if (is_array($node)) {
            return array_map([self::class, 'toArrayDeep'], $node);
        }

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    public static function errorEnvelope(mixed $id, JsonRpcException $e): array
    {
        $error = [
            'code' => $e->rpcCode,
            'message' => $e->getMessage(),
        ];

        if ($e->data !== null) {
            $error['data'] = $e->data;
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => $error,
        ];
    }
}
