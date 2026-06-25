<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\LockableCacheInterface;
use App\Application\Contracts\LockManagerInterface;
use App\Application\Contracts\ResilientCacheInterface;
use App\Infrastructure\BingAds\BingAdsSessionManager;
use App\Infrastructure\Linnworks\LinnworksSessionManager;
use App\Infrastructure\Locking\CacheLockManager;
use App\Infrastructure\Support\LockableCache;
use App\Infrastructure\Support\ResilientCache;
use App\Infrastructure\Support\TransientLogThrottle;
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
        $this->registerDefaultCache();
        $this->registerContextualCaches();
        $this->registerResilientCache();
        $this->registerTransientLogThrottle();
        $this->registerLockManager();
    }

    private function registerDefaultCache(): void
    {
        $this->app->singleton(
            LockableCacheInterface::class,
            static fn(Application $app): LockableCacheInterface => new LockableCache(
                cache: $app->make(CacheManager::class),
                logger: $app->make(LoggerInterface::class),
            ),
        );
    }

    private function registerContextualCaches(): void
    {
        $this->app->when(BingAdsSessionManager::class)
            ->needs(LockableCacheInterface::class)
            ->give(static fn(Application $app): LockableCacheInterface => new LockableCache(
                cache: $app->make(CacheManager::class),
                logger: $app->make(LoggerInterface::class),
                serviceName: 'bingads',
            ));

        $this->app->when(LinnworksSessionManager::class)
            ->needs(LockableCacheInterface::class)
            ->give(static fn(Application $app): LockableCacheInterface => new LockableCache(
                cache: $app->make(CacheManager::class),
                logger: $app->make(LoggerInterface::class),
                serviceName: 'linnworks',
            ));
    }

    private function registerResilientCache(): void
    {
        $this->app->singleton(
            ResilientCacheInterface::class,
            static fn(Application $app): ResilientCacheInterface => new ResilientCache(
                cache: $app->make(CacheManager::class),
                logger: $app->make(LoggerInterface::class),
            ),
        );
    }

    private function registerTransientLogThrottle(): void
    {
        $this->app->singleton(
            TransientLogThrottle::class,
            static fn(Application $app): TransientLogThrottle => new TransientLogThrottle(
                cache: $app->make(CacheManager::class),
                logger: $app->make(LoggerInterface::class),
            ),
        );
    }

    private function registerLockManager(): void
    {
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
            ResilientCacheInterface::class,
            TransientLogThrottle::class,
        ];
    }
}
