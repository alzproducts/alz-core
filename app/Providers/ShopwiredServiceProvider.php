<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\Shopwired\CategoryClientInterface;
use App\Application\Contracts\Shopwired\ConnectivityClientInterface;
use App\Application\Contracts\Shopwired\CustomerClientInterface;
use App\Application\Contracts\Shopwired\CustomerRepositoryInterface;
use App\Application\Contracts\Shopwired\CustomFieldClientInterface;
use App\Application\Contracts\Shopwired\CustomFieldRepositoryInterface;
use App\Application\Contracts\Shopwired\OrderClientInterface;
use App\Application\Contracts\Shopwired\OrderRepositoryInterface;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Contracts\Shopwired\StockClientInterface;
use App\Infrastructure\Shopwired\Factories\ProductCustomFieldFactory;
use App\Infrastructure\Shopwired\Mappers\ProductModelMapper;
use App\Infrastructure\Shopwired\Repositories\EloquentCustomerRepository;
use App\Infrastructure\Shopwired\Repositories\EloquentCustomFieldRepository;
use App\Infrastructure\Shopwired\Repositories\EloquentOrderRepository;
use App\Infrastructure\Shopwired\Repositories\EloquentProductRepository;
use App\Infrastructure\Shopwired\ShopwiredClientFactory;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * ShopWired API Client
 *
 * Deferred provider for ShopWired endpoint clients - only loads when requested.
 * Configuration validation is handled by the Factory (fail-fast pattern).
 *
 * Architecture: All endpoint clients share a single ShopwiredHttpTransport
 * instance managed by the factory (lazy singleton pattern).
 *
 * @template-pattern API Client Service Provider
 */
final class ShopwiredServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Register ShopWired API clients.
     *
     * Delegates to ShopwiredClientFactory which handles:
     * - Configuration validation (fail-fast with RuntimeException)
     * - Dependency wiring (Config → Transport → Client)
     * - Transport singleton management (shared across all clients)
     */
    #[Override]
    public function register(): void
    {
        // Connectivity client - for API health checks
        $this->app->singleton(
            ConnectivityClientInterface::class,
            static fn(): ConnectivityClientInterface => ShopwiredClientFactory::createConnectivityClient(),
        );

        // Category client - for category operations
        $this->app->singleton(
            CategoryClientInterface::class,
            static fn(): CategoryClientInterface => ShopwiredClientFactory::createCategoryClient(),
        );

        // Custom field client - for custom field definitions
        $this->app->singleton(
            CustomFieldClientInterface::class,
            static fn(): CustomFieldClientInterface => ShopwiredClientFactory::createCustomFieldClient(),
        );

        // Customer client - for customer operations
        $this->app->singleton(
            CustomerClientInterface::class,
            static fn(): CustomerClientInterface => ShopwiredClientFactory::createCustomerClient(),
        );

        // Order client - for order operations
        $this->app->singleton(
            OrderClientInterface::class,
            static fn(): OrderClientInterface => ShopwiredClientFactory::createOrderClient(),
        );

        // Stock client - for stock quantity updates
        $this->app->singleton(
            StockClientInterface::class,
            static fn(): StockClientInterface => ShopwiredClientFactory::createStockClient(),
        );

        // Order repository - for local database persistence
        $this->app->singleton(
            OrderRepositoryInterface::class,
            EloquentOrderRepository::class,
        );

        // Customer repository - for local database persistence
        $this->app->singleton(
            CustomerRepositoryInterface::class,
            EloquentCustomerRepository::class,
        );

        // Custom field repository - for local database persistence
        $this->app->singleton(
            CustomFieldRepositoryInterface::class,
            EloquentCustomFieldRepository::class,
        );

        // Product custom field factory - scoped to prevent stale registry in Octane
        $this->app->scoped(ProductCustomFieldFactory::class);

        // Product model mapper - scoped as it depends on ProductCustomFieldFactory
        $this->app->scoped(ProductModelMapper::class);

        // Product repository - scoped for fresh mapper per queue job
        $this->app->scoped(
            ProductRepositoryInterface::class,
            EloquentProductRepository::class,
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
            CategoryClientInterface::class,
            ConnectivityClientInterface::class,
            CustomFieldClientInterface::class,
            CustomFieldRepositoryInterface::class,
            CustomerClientInterface::class,
            CustomerRepositoryInterface::class,
            OrderClientInterface::class,
            OrderRepositoryInterface::class,
            ProductCustomFieldFactory::class,
            ProductModelMapper::class,
            ProductRepositoryInterface::class,
            StockClientInterface::class,
        ];
    }
}
