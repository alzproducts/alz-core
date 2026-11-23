<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\QuickBooks\OrderSyncErrorRepositoryInterface;
use App\Application\Contracts\QuickBooks\OrderSyncRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksAccessTokenCacheInterface;
use App\Application\Contracts\QuickBooks\QuickBooksClientInterface;
use App\Application\Contracts\QuickBooks\QuickBooksConnectionRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksMappingCandidatesInterface;
use App\Application\Contracts\QuickBooks\QuickBooksMappingRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksOAuthClientInterface;
use App\Application\Contracts\QuickBooks\QuickBooksOrderPostingInterface;
use App\Application\Contracts\QuickBooks\QuickBooksRealmInterface;
use App\Application\Contracts\QuickBooks\QuickBooksStateStoreInterface;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Infrastructure\QuickBooks\CacheQuickBooksAccessTokenCache;
use App\Infrastructure\QuickBooks\CacheQuickBooksStateStore;
use App\Infrastructure\QuickBooks\EloquentOrderSyncErrorRepository;
use App\Infrastructure\QuickBooks\EloquentOrderSyncRepository;
use App\Infrastructure\QuickBooks\EloquentQuickBooksConnectionRepository;
use App\Infrastructure\QuickBooks\EloquentQuickBooksMappingRepository;
use App\Infrastructure\QuickBooks\QuickBooksClient;
use App\Infrastructure\QuickBooks\QuickBooksConfig;
use App\Infrastructure\QuickBooks\QuickBooksConfigFactory;
use App\Infrastructure\QuickBooks\QuickBooksMappingCandidateReader;
use App\Infrastructure\QuickBooks\QuickBooksOAuthClient;
use App\Infrastructure\QuickBooks\QuickBooksRateLimiter;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Override;
use Psr\Log\LoggerInterface;

/**
 * Deferred: boot never resolves the QBO config, so blank keys fail on first use (ADR-0015).
 */
final class QuickBooksServiceProvider extends ServiceProvider implements DeferrableProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->singleton(
            QuickBooksConfig::class,
            static fn(): QuickBooksConfig => QuickBooksConfigFactory::create(),
        );

        $this->app->bind(QuickBooksConnectionRepositoryInterface::class, EloquentQuickBooksConnectionRepository::class);
        $this->app->bind(OrderSyncRepositoryInterface::class, EloquentOrderSyncRepository::class);
        $this->app->bind(OrderSyncErrorRepositoryInterface::class, EloquentOrderSyncErrorRepository::class);
        $this->app->bind(QuickBooksMappingRepositoryInterface::class, EloquentQuickBooksMappingRepository::class);
        $this->app->bind(QuickBooksOAuthClientInterface::class, QuickBooksOAuthClient::class);
        $this->app->bind(QuickBooksClientInterface::class, QuickBooksClient::class);
        $this->app->bind(QuickBooksOrderPostingInterface::class, QuickBooksClient::class);
        $this->app->bind(QuickBooksMappingCandidatesInterface::class, QuickBooksMappingCandidateReader::class);
        $this->app->bind(QuickBooksRealmInterface::class, QuickBooksConfig::class);
        $this->registerRateLimiter();
        $this->registerCacheBackedStores();
    }

    private function registerRateLimiter(): void
    {
        $this->app->bind(
            QuickBooksRateLimiter::class,
            static fn(Application $app): QuickBooksRateLimiter => new QuickBooksRateLimiter(
                redis: $app->make(RedisFactory::class),
                realmId: $app->make(QuickBooksConfig::class)->realmId,
                logger: $app->make(LoggerInterface::class),
                buckets: QuickBooksRateLimiter::defaultBuckets(),
            ),
        );
    }

    private function registerCacheBackedStores(): void
    {
        $this->app->bind(
            QuickBooksAccessTokenCacheInterface::class,
            static fn(Application $app): QuickBooksAccessTokenCacheInterface => new CacheQuickBooksAccessTokenCache(
                store: self::cacheStore($app),
                config: $app->make(QuickBooksConfig::class),
                logger: $app->make(LoggerInterface::class),
            ),
        );
        $this->app->bind(
            QuickBooksStateStoreInterface::class,
            static fn(Application $app): QuickBooksStateStoreInterface => new CacheQuickBooksStateStore(
                store: self::cacheStore($app),
                config: $app->make(QuickBooksConfig::class),
                logger: $app->make(LoggerInterface::class),
            ),
        );
    }

    /**
     * @return list<class-string>
     */
    #[Override]
    public function provides(): array
    {
        return [
            QuickBooksConfig::class,
            QuickBooksConnectionRepositoryInterface::class,
            OrderSyncRepositoryInterface::class,
            OrderSyncErrorRepositoryInterface::class,
            QuickBooksMappingRepositoryInterface::class,
            QuickBooksOAuthClientInterface::class,
            QuickBooksClientInterface::class,
            QuickBooksOrderPostingInterface::class,
            QuickBooksMappingCandidatesInterface::class,
            QuickBooksRealmInterface::class,
            QuickBooksRateLimiter::class,
            QuickBooksAccessTokenCacheInterface::class,
            QuickBooksStateStoreInterface::class,
        ];
    }

    /**
     * @throws InvalidConfigurationException
     */
    private static function cacheStore(Application $app): Repository
    {
        $store = \config('quickbooks.cache_store');
        if (!\is_string($store) || $store === '') {
            throw new InvalidConfigurationException('QUICKBOOKS_CACHE_STORE');
        }

        return $app->make(CacheManager::class)->store($store);
    }
}
