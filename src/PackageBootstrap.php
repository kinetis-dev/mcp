<?php

declare(strict_types=1);

namespace Kinetis\Mcp;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\PackageBootstrapInterface;
use Kinetis\McpProtocol\McpServer;
use Kinetis\McpProtocol\ServerInfo;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Declared via `extra.kinetis`: binds the shared {@see McpServer} around
 * this package's own {@see KinetisMcpApplication}, so the /mcp route
 * ({@see Http\McpController}, discovered from this package's own scan
 * root) and `kinetis mcp:serve` resolve one shared server with nothing to
 * register. Installing the package is the whole setup.
 *
 * {@see McpRegistry} is not discovered here at all — it is declared as
 * this package's own `extra.kinetis` `discovery` class instead, so the
 * framework compiles, caches and binds it before this method ever runs
 * (see `Kinetis\Cache\PluginDiscovery`). This factory assembles the
 * runtime server around whatever is already bound, resolved on first use:
 * a `/mcp` request or an `mcp:serve` boot that never happens never pays
 * for it.
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

            return new McpServer(
                new ServerInfo('Kinetis', '1.0.0'),
                new KinetisMcpApplication($registry, new McpDispatcher($container), $logger),
            );
        });
    }
}
