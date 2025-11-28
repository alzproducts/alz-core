<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Infrastructure\Linnworks\LinnworksClientFactory;
use Illuminate\Contracts\Support\DeferrableProvider;
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
        // Inventory client - for stock item operations
        $this->app->singleton(
            InventoryClientInterface::class,
            static fn(): InventoryClientInterface => LinnworksClientFactory::createInventoryClient(),
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
            InventoryClientInterface::class,
        ];
    }
}
