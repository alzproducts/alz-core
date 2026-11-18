<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\QuickBooks\QuickBooksAccessTokenCacheInterface;
use App\Application\Contracts\QuickBooks\QuickBooksConnectionRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksOAuthClientInterface;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Infrastructure\QuickBooks\CacheQuickBooksAccessTokenCache;
use App\Infrastructure\QuickBooks\EloquentQuickBooksConnectionRepository;
use App\Infrastructure\QuickBooks\QuickBooksConfig;
use App\Infrastructure\QuickBooks\QuickBooksConfigFactory;
use App\Infrastructure\QuickBooks\QuickBooksOAuthClient;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
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
        $this->app->bind(QuickBooksOAuthClientInterface::class, QuickBooksOAuthClient::class);
        $this->app->bind(
            QuickBooksAccessTokenCacheInterface::class,
            static fn(Application $app): QuickBooksAccessTokenCacheInterface => new CacheQuickBooksAccessTokenCache(
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
            QuickBooksOAuthClientInterface::class,
            QuickBooksAccessTokenCacheInterface::class,
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
