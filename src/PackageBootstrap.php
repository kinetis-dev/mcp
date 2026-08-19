<?php

declare(strict_types=1);

namespace Kinetis\Mcp;

use Composer\InstalledVersions;
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
 * The binding is a factory, resolved on first use rather than here —
 * which is also where the cost lands: discovery runs when something
 * actually resolves the server (a /mcp request, an mcp:serve boot),
 * never on requests that don't. Under a persistent worker that is once
 * per worker; under PHP-FPM it is once per /mcp request, bounded by
 * NamespaceScanner's attribute pre-filter like every other discovery.
 */
final readonly class PackageBootstrap implements PackageBootstrapInterface
{
    #[\Override]
    public function register(AppScope $app, Config $config): void
    {
        $app->bind(McpServer::class, static function (ContainerInterface $container): McpServer {
            $registry = McpDiscovery::discover(self::projectRoot());

            /** @var LoggerInterface $logger */
            $logger = $container->get(LoggerInterface::class);

            return new McpServer($registry, new McpDispatcher($container), logger: $logger);
        });
    }

    /**
     * The consumer project's own root — what discovery scans. Composer's
     * runtime API reports the root package's install path, which is the
     * application when this package is installed as a dependency, and
     * this package itself when its own test suite runs.
     */
    private static function projectRoot(): string
    {
        $root = InstalledVersions::getRootPackage()['install_path'];

        return \rtrim($root, '/');
    }
}
