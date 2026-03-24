<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\DatabaseGatewayInterface;
use App\Application\Contracts\Linnworks\ConnectivityClientInterface;
use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Application\Contracts\Linnworks\InventoryUpdateClientInterface;
use App\Application\Contracts\Linnworks\LinnworksOrderRepositoryInterface;
use App\Application\Contracts\Linnworks\LinnworksSyncDispatcherInterface;
use App\Application\Contracts\Linnworks\OrderClientInterface;
use App\Application\Contracts\Linnworks\PurchaseOrderClientInterface;
use App\Application\Contracts\Linnworks\StockDashboardsClientInterface;
use App\Application\Contracts\Linnworks\StockItemRepositoryInterface;
use App\Application\Contracts\Linnworks\SupplierRepositoryInterface;
use App\Application\Contracts\LockableCacheInterface;
use App\Infrastructure\Linnworks\Dispatchers\QueuedLinnworksSyncDispatcher;
use App\Infrastructure\Linnworks\LinnworksClientFactory;
use App\Infrastructure\Linnworks\LinnworksConfig;
use App\Infrastructure\Linnworks\LinnworksSessionManager;
use App\Infrastructure\Linnworks\Repositories\EloquentLinnworksOrderRepository;
use App\Infrastructure\Linnworks\Repositories\EloquentStockItemRepository;
use App\Infrastructure\Linnworks\Repositories\EloquentSupplierRepository;
use App\Infrastructure\Persistence\EloquentGateway;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Linnworks API Client Service Provider.
 *
 * Deferred provider for Linnworks endpoint clients - only loads when requested.
 * Configuration validation is handled by the Factory (fail-fast pattern).
 *
 * Architecture: All endpoint clients share a single LinnworksHttpTransport
 * instance managed by the factory (lazy singleton pattern). The transport
 * handles session-based authentication with automatic 401 retry.
 *
 * @template-pattern API Client Service Provider
 */
final class LinnworksServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Register Linnworks API clients.
     *
     * Delegates to LinnworksClientFactory which handles:
     * - Configuration validation (fail-fast with RuntimeException)
     * - Session management (auth, caching, refresh)
     * - Dependency wiring (Config → SessionManager → Transport → Client)
     * - Transport singleton management (shared across all clients)
     */
    #[Override]
    public function register(): void
    {
        // LinnworksSessionManager with contextual LockableCacheInterface
        $this->app->singleton(
            LinnworksSessionManager::class,
            static fn(Container $app): LinnworksSessionManager => new LinnworksSessionManager(
                self::createConfig(),
                $app->make(LockableCacheInterface::class),
            ),
        );

        // Connectivity client - for health checks
        $this->app->singleton(
            ConnectivityClientInterface::class,
            static fn(): ConnectivityClientInterface => LinnworksClientFactory::createConnectivityClient(),
        );

        // Inventory client - for stock item operations
        $this->app->singleton(
            InventoryClientInterface::class,
            static fn(): InventoryClientInterface => LinnworksClientFactory::createInventoryClient(),
        );

        // Inventory update client - for modifying stock items (SKU updates, etc.)
        $this->app->singleton(
            InventoryUpdateClientInterface::class,
            static fn(): InventoryUpdateClientInterface => LinnworksClientFactory::createInventoryUpdateClient(),
        );

        // Stock dashboards client - for SQL queries including soft-deleted items
        $this->app->singleton(
            StockDashboardsClientInterface::class,
            static fn(): StockDashboardsClientInterface => LinnworksClientFactory::createStockDashboardsClient(),
        );

        // Stock item repository - for persisting synced stock items
        $this->app->singleton(
            StockItemRepositoryInterface::class,
            static fn(Container $app): StockItemRepositoryInterface => new EloquentStockItemRepository(
                $app->make(DatabaseGatewayInterface::class),
                $app->make(EloquentGateway::class),
            ),
        );

        // Supplier repository - for persisting synced supplier directory
        $this->app->singleton(
            SupplierRepositoryInterface::class,
            static fn(Container $app): SupplierRepositoryInterface => new EloquentSupplierRepository(
                $app->make(DatabaseGatewayInterface::class),
                $app->make(EloquentGateway::class),
            ),
        );

        // Order client - for fetching processed orders from v2 GetOrders API
        $this->app->singleton(
            OrderClientInterface::class,
            static fn(): OrderClientInterface => LinnworksClientFactory::createOrderClient(),
        );

        // Purchase order client - for PO lifecycle management
        $this->app->singleton(
            PurchaseOrderClientInterface::class,
            static fn(): PurchaseOrderClientInterface => LinnworksClientFactory::createPurchaseOrderClient(),
        );

        // Dispatchers
        $this->app->singleton(LinnworksSyncDispatcherInterface::class, QueuedLinnworksSyncDispatcher::class);

        // Order repository - for persisting synced processed orders
        $this->app->singleton(
            LinnworksOrderRepositoryInterface::class,
            static fn(Container $app): LinnworksOrderRepositoryInterface => new EloquentLinnworksOrderRepository(
                $app->make(DatabaseGatewayInterface::class),
                $app->make(EloquentGateway::class),
            ),
        );
    }

    /**
     * Get the services provided by the provider.
     *
     * @return list<class-string>
     */
    #[Override]
    public function provides(): array
    {
        return [
            ConnectivityClientInterface::class,
            InventoryClientInterface::class,
            InventoryUpdateClientInterface::class,
            LinnworksOrderRepositoryInterface::class,
            LinnworksSyncDispatcherInterface::class,
            LinnworksSessionManager::class,
            OrderClientInterface::class,
            PurchaseOrderClientInterface::class,
            StockDashboardsClientInterface::class,
            StockItemRepositoryInterface::class,
            SupplierRepositoryInterface::class,
        ];
    }

    /**
     * Create LinnworksConfig from Laravel configuration.
     *
     * LinnworksConfig constructor handles validation (fail-fast for invalid config).
     */
    private static function createConfig(): LinnworksConfig
    {
        return new LinnworksConfig(
            applicationId: Config::string('linnworks.application_id', ''),
            applicationSecret: Config::string('linnworks.application_secret', ''),
            installationToken: Config::string('linnworks.installation_token', ''),
            timeout: Config::integer('linnworks.timeout', 30),
            cacheTtlBuffer: Config::integer('linnworks.cache_ttl_buffer', 300),
        );
    }
}
