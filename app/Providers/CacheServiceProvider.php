<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\LockableCacheInterface;
use App\Application\Contracts\LockManagerInterface;
use App\Infrastructure\BingAds\BingAdsSessionManager;
use App\Infrastructure\Linnworks\LinnworksSessionManager;
use App\Infrastructure\Locking\CacheLockManager;
use App\Infrastructure\Support\LockableCache;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Override;
use Psr\Log\LoggerInterface;

/**
 * Cache Service Provider.
 *
 * Provides LockableCacheInterface with service-specific contextual bindings.
 * Each integration (BingAds, Linnworks, etc.) gets its own LockableCache instance
 * with appropriate serviceName for differentiated logging.
 *
 * LockableCache provides:
 * - Thundering herd protection via atomic locks
 * - Graceful degradation on cache/lock failures
 * - Stale value fallback option
 */
final class CacheServiceProvider extends ServiceProvider implements DeferrableProvider
{
    #[Override]
    public function register(): void
    {
        // Default binding (generic serviceName)
        $this->app->singleton(
            LockableCacheInterface::class,
            static fn(Application $app): LockableCacheInterface => new LockableCache(
                cache: $app->make(CacheManager::class),
                logger: $app->make(LoggerInterface::class),
            ),
        );

        // Contextual: BingAds gets "bingads" serviceName for logging
        $this->app->when(BingAdsSessionManager::class)
            ->needs(LockableCacheInterface::class)
            ->give(static fn(Application $app): LockableCacheInterface => new LockableCache(
                cache: $app->make(CacheManager::class),
                logger: $app->make(LoggerInterface::class),
                serviceName: 'bingads',
            ));

        // Contextual: Linnworks gets "linnworks" serviceName for logging
        $this->app->when(LinnworksSessionManager::class)
            ->needs(LockableCacheInterface::class)
            ->give(static fn(Application $app): LockableCacheInterface => new LockableCache(
                cache: $app->make(CacheManager::class),
                logger: $app->make(LoggerInterface::class),
                serviceName: 'linnworks',
            ));

        // LockManagerInterface: strict locking for critical operations (no graceful degradation)
        $this->app->singleton(
            LockManagerInterface::class,
            static fn(Application $app): LockManagerInterface => new CacheLockManager(
                cache: $app->make(CacheManager::class),
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
            LockableCacheInterface::class,
            LockManagerInterface::class,
        ];
    }
}
