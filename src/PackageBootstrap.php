<?php

declare(strict_types=1);

namespace Kinetis\Mcp;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\PackageBootstrapInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Declared via `extra.kinetis`: binds {@see McpServer} so the /mcp route
 * ({@see Http\McpController}, discovered from this package's own scan
 * root) and `kinetis mcp:serve` resolve one shared server with nothing
 * to register. Installing the package is the whole setup.
 *
 * {@see McpRegistry} is not discovered here at all — it's declared as
 * this package's own `extra.kinetis` `discovery` class instead, so the
 * framework itself compiles, caches, and binds it before this method
 * ever runs (see `Kinetis\Cache\PluginDiscovery`). This factory just
 * assembles the runtime server around whatever's already bound, resolved
 * on first use — a `/mcp` request or an `mcp:serve` boot that never
 * happens never pays for it.
 */
final readonly class PackageBootstrap implements PackageBootstrapInterface
{
    #[\Override]
    public function register(AppScope $app, Config $config): void
    {
        $app->bind(McpServer::class, static function (ContainerInterface $container): McpServer {
            /** @var McpRegistry $registry */
            $registry = $container->get(McpRegistry::class);
            /** @var LoggerInterface $logger */
            $logger = $container->get(LoggerInterface::class);

            return new McpServer($registry, new McpDispatcher($container), logger: $logger);
        });
    }
}
