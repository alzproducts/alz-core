<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\Shopwired\CategoryClientInterface;
use App\Application\Contracts\Shopwired\ConnectivityClientInterface;
use App\Application\Contracts\Shopwired\CustomerClientInterface;
use App\Infrastructure\Shopwired\ShopwiredClientFactory;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * ShopWired API Client Service Provider.
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

        // Customer client - for customer operations
        $this->app->singleton(
            CustomerClientInterface::class,
            static fn(): CustomerClientInterface => ShopwiredClientFactory::createCustomerClient(),
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
            CategoryClientInterface::class,
            CustomerClientInterface::class,
        ];
    }
}
