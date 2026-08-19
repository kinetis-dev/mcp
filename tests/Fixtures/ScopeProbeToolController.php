<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests\Fixtures;

use Kinetis\Container\RequestScope;
use Kinetis\Mcp\Attributes\McpTool;

/**
 * Observes the scope its own call runs in, which is the whole point of
 * the per-message lifecycle: the injected RequestScope must be the live
 * scope of this call, not one shared with the previous call. Both
 * dependencies are constructor-injected — a tool's method parameters
 * come from the JSON-RPC arguments object, never the container.
 */
final readonly class ScopeProbeToolController
{
    public function __construct(
        private RequestScope $scope,
        private DisposeRecorder $recorder,
    ) {}

    /**
     * Marks this call's scope and reports whether an earlier call's mark
     * was still visible — cached instances live exactly as long as their
     * scope, so per-message scopes answer false on every call.
     *
     * @return array{sawEarlierMarker: bool, scopeObjectId: int}
     */
    #[McpTool(name: 'probe_scope', description: 'Reports scope identity across calls')]
    public function probe(): array
    {
        $marker = $this->scope->get(ScopeMarker::class);
        $saw = $marker->seen;
        $marker->seen = true;

        return ['sawEarlierMarker' => $saw, 'scopeObjectId' => spl_object_id($this->scope)];
    }

    /**
     * Registers a dispose hook counting onto the AppScope-held recorder,
     * so the hook's effect stays observable after the scope is gone —
     * and only reaches the shared recorder at all if this scope is
     * connected to the real AppScope rather than a disconnected
     * autowired one.
     *
     * @return array{registered: bool}
     */
    #[McpTool(name: 'register_dispose_hook', description: 'Registers a dispose hook on this call\'s scope')]
    public function registerDisposeHook(): array
    {
        $recorder = $this->recorder;
        $this->scope->onDispose(static function () use ($recorder): void {
            $recorder->disposals++;
        });

        return ['registered' => true];
    }
}
