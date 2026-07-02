<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\Catalog\CostPriceChangeLogRepositoryInterface;
use App\Application\Contracts\DatabaseGatewayInterface;
use App\Application\Contracts\Linnworks\ConnectivityClientInterface;
use App\Application\Contracts\Linnworks\CostPriceUpdateDispatcherInterface;
use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Application\Contracts\Linnworks\InventoryFieldUpdateClientInterface;
use App\Application\Contracts\Linnworks\InventoryUpdateClientInterface;
use App\Application\Contracts\Linnworks\LinnworksBackfillDispatcherInterface;
use App\Application\Contracts\Linnworks\LinnworksOrderRepositoryInterface;
use App\Application\Contracts\Linnworks\LinnworksSyncDispatcherInterface;
use App\Application\Contracts\Linnworks\OrderClientInterface;
use App\Application\Contracts\Linnworks\OrderDashboardsClientInterface;
use App\Application\Contracts\Linnworks\PurchaseDashboardsClientInterface;
use App\Application\Contracts\Linnworks\PurchaseOrderBackfillDispatcherInterface;
use App\Application\Contracts\Linnworks\PurchaseOrderClientInterface;
use App\Application\Contracts\Linnworks\PurchaseOrderSyncRepositoryInterface;
use App\Application\Contracts\Linnworks\PurchaseOrderUpdateClientInterface;
use App\Application\Contracts\Linnworks\StockDashboardsClientInterface;
use App\Application\Contracts\Linnworks\StockItemRepositoryInterface;
use App\Application\Contracts\Linnworks\StockItemSupplierRepositoryInterface;
use App\Application\Contracts\Linnworks\SupplierRepositoryInterface;
use App\Application\Contracts\LockableCacheInterface;
use App\Infrastructure\Catalog\Repositories\EloquentCostPriceChangeLogRepository;
use App\Infrastructure\Linnworks\Dispatchers\QueuedCostPriceUpdateDispatcher;
use App\Infrastructure\Linnworks\Dispatchers\QueuedLinnworksBackfillDispatcher;
use App\Infrastructure\Linnworks\Dispatchers\QueuedLinnworksSyncDispatcher;
use App\Infrastructure\Linnworks\Dispatchers\QueuedPurchaseOrderBackfillDispatcher;
use App\Infrastructure\Linnworks\LinnworksClientFactory;
use App\Infrastructure\Linnworks\LinnworksConfig;
use App\Infrastructure\Linnworks\LinnworksSessionManager;
use App\Infrastructure\Linnworks\Repositories\EloquentLinnworksOrderRepository;
use App\Infrastructure\Linnworks\Repositories\EloquentPurchaseOrderSyncRepository;
use App\Infrastructure\Linnworks\Repositories\EloquentStockItemRepository;
use App\Infrastructure\Linnworks\Repositories\EloquentStockItemSupplierRepository;
use App\Infrastructure\Linnworks\Repositories\EloquentSupplierRepository;
use App\Infrastructure\Persistence\EloquentGateway;
use App\Infrastructure\Support\TransientLogThrottle;
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
        $this->registerSessionManager();
        $this->registerStockClients();
        $this->registerInventoryClients();
        $this->registerOrderClients();
        $this->registerPurchaseOrderClients();
        $this->registerStockRepositories();
        $this->registerCostPriceChangeLogRepository();
        $this->registerSupplierRepository();
        $this->registerOrderRepositories();
        $this->registerDispatchers();
    }

    private function registerSessionManager(): void
    {
        $this->app->singleton(
            LinnworksSessionManager::class,
            static fn(Container $app): LinnworksSessionManager => new LinnworksSessionManager(
                self::createConfig(),
                $app->make(LockableCacheInterface::class),
            ),
        );
    }

    private function registerStockClients(): void
    {
        $this->app->singleton(
            ConnectivityClientInterface::class,
            static fn(): ConnectivityClientInterface => LinnworksClientFactory::createConnectivityClient(),
        );
        $this->app->singleton(
            StockDashboardsClientInterface::class,
            static fn(Container $app): StockDashboardsClientInterface => LinnworksClientFactory::createStockDashboardsClient($app->make(TransientLogThrottle::class)),
        );
    }

    private function registerInventoryClients(): void
    {
        $this->app->singleton(
            InventoryClientInterface::class,
            static fn(Container $app): InventoryClientInterface => LinnworksClientFactory::createInventoryClient($app->make(TransientLogThrottle::class)),
        );
        $this->app->singleton(
            InventoryUpdateClientInterface::class,
            static fn(Container $app): InventoryUpdateClientInterface => LinnworksClientFactory::createInventoryUpdateClient($app->make(TransientLogThrottle::class)),
        );
        $this->app->singleton(
            InventoryFieldUpdateClientInterface::class,
            static fn(Container $app): InventoryFieldUpdateClientInterface => LinnworksClientFactory::createInventoryFieldUpdateClient($app->make(TransientLogThrottle::class)),
        );
    }

    private function registerOrderClients(): void
    {
        $this->app->singleton(
            OrderDashboardsClientInterface::class,
            static fn(Container $app): OrderDashboardsClientInterface => LinnworksClientFactory::createOrderDashboardsClient($app->make(TransientLogThrottle::class)),
        );
        $this->app->singleton(
            OrderClientInterface::class,
            static fn(Container $app): OrderClientInterface => LinnworksClientFactory::createOrderClient($app->make(TransientLogThrottle::class)),
        );
    }

    private function registerPurchaseOrderClients(): void
    {
        $this->app->singleton(
            PurchaseOrderClientInterface::class,
            static fn(Container $app): PurchaseOrderClientInterface => LinnworksClientFactory::createPurchaseOrderClient($app->make(TransientLogThrottle::class)),
        );
        $this->app->singleton(
            PurchaseOrderUpdateClientInterface::class,
            static fn(Container $app): PurchaseOrderUpdateClientInterface => LinnworksClientFactory::createPurchaseOrderUpdateClient($app->make(TransientLogThrottle::class)),
        );
        $this->app->singleton(
            PurchaseDashboardsClientInterface::class,
            static fn(Container $app): PurchaseDashboardsClientInterface => LinnworksClientFactory::createPurchaseDashboardsClient($app->make(TransientLogThrottle::class)),
        );
    }

    private function registerStockRepositories(): void
    {
        $this->app->singleton(
            StockItemRepositoryInterface::class,
            static fn(Container $app): StockItemRepositoryInterface => new EloquentStockItemRepository(
                $app->make(DatabaseGatewayInterface::class),
                $app->make(EloquentGateway::class),
            ),
        );

        $this->app->singleton(
            StockItemSupplierRepositoryInterface::class,
            static fn(Container $app): StockItemSupplierRepositoryInterface => new EloquentStockItemSupplierRepository(
                $app->make(EloquentGateway::class),
            ),
        );
    }

    private function registerCostPriceChangeLogRepository(): void
    {
        $this->app->singleton(
            CostPriceChangeLogRepositoryInterface::class,
            static fn(Container $app): CostPriceChangeLogRepositoryInterface => new EloquentCostPriceChangeLogRepository(
                $app->make(EloquentGateway::class),
            ),
        );
    }

    private function registerSupplierRepository(): void
    {
        $this->app->singleton(
            SupplierRepositoryInterface::class,
            static fn(Container $app): SupplierRepositoryInterface => new EloquentSupplierRepository(
                $app->make(DatabaseGatewayInterface::class),
                $app->make(EloquentGateway::class),
            ),
        );
    }

    private function registerOrderRepositories(): void
    {
        $this->app->singleton(
            LinnworksOrderRepositoryInterface::class,
            static fn(Container $app): LinnworksOrderRepositoryInterface => new EloquentLinnworksOrderRepository(
                $app->make(DatabaseGatewayInterface::class),
                $app->make(EloquentGateway::class),
            ),
        );

        $this->app->singleton(
            PurchaseOrderSyncRepositoryInterface::class,
            static fn(Container $app): PurchaseOrderSyncRepositoryInterface => new EloquentPurchaseOrderSyncRepository(
                $app->make(DatabaseGatewayInterface::class),
                $app->make(EloquentGateway::class),
            ),
        );
    }

    private function registerDispatchers(): void
    {
        $this->app->singleton(LinnworksSyncDispatcherInterface::class, QueuedLinnworksSyncDispatcher::class);
        $this->app->singleton(LinnworksBackfillDispatcherInterface::class, QueuedLinnworksBackfillDispatcher::class);
        $this->app->singleton(PurchaseOrderBackfillDispatcherInterface::class, QueuedPurchaseOrderBackfillDispatcher::class);
        $this->app->singleton(CostPriceUpdateDispatcherInterface::class, QueuedCostPriceUpdateDispatcher::class);
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
            CostPriceChangeLogRepositoryInterface::class,
            CostPriceUpdateDispatcherInterface::class,
            InventoryClientInterface::class,
            InventoryFieldUpdateClientInterface::class,
            InventoryUpdateClientInterface::class,
            LinnworksOrderRepositoryInterface::class,
            LinnworksBackfillDispatcherInterface::class,
            LinnworksSyncDispatcherInterface::class,
            LinnworksSessionManager::class,
            OrderClientInterface::class,
            OrderDashboardsClientInterface::class,
            PurchaseOrderBackfillDispatcherInterface::class,
            PurchaseDashboardsClientInterface::class,
            PurchaseOrderClientInterface::class,
            PurchaseOrderSyncRepositoryInterface::class,
            PurchaseOrderUpdateClientInterface::class,
            StockDashboardsClientInterface::class,
            StockItemRepositoryInterface::class,
            StockItemSupplierRepositoryInterface::class,
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
