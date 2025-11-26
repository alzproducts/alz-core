<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\ShopwiredClientInterface;
use App\Infrastructure\Shopwired\ShopwiredClientFactory;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Shopwired API Client Service Provider.
 *
 * Deferred provider for ShopwiredClient - only loads when the service is requested.
 * Configuration validation is handled by the Factory (fail-fast pattern).
 *
 * @template-pattern API Client Service Provider
 */
final class ShopwiredServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Register Shopwired API client.
     *
     * Delegates to ShopwiredClientFactory which handles:
     * - Configuration validation (fail-fast with RuntimeException)
     * - Dependency wiring (Config → Transport → Client)
     */
    #[Override]
    public function register(): void
    {
        $this->app->singleton(
            ShopwiredClientInterface::class,
            static fn(): ShopwiredClientInterface => ShopwiredClientFactory::create(),
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
        return [ShopwiredClientInterface::class];
    }
}
