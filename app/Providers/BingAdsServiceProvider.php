<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\BingAdsClientInterface;
use App\Infrastructure\BingAds\BingAdsClientFactory;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Bing Ads (Microsoft Advertising) Service Provider.
 *
 * Deferred provider for Bing Ads client. Service is only loaded when requested,
 * allowing other features to function even if Bing Ads configuration is missing.
 * Configuration and currency validation is handled by BingAdsClientFactory.
 *
 * Key differences from Google Ads:
 * - Manual OAuth token management (SessionManager handles refresh)
 * - Currency validation at boot time (fail-fast for non-GBP accounts)
 * - SOAP-based API (vs gRPC for Google Ads)
 */
final class BingAdsServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Register Bing Ads client binding.
     *
     * Configuration and currency validation happens when the service
     * is first resolved via BingAdsClientFactory.
     */
    #[Override]
    public function register(): void
    {
        $this->app->singleton(
            BingAdsClientInterface::class,
            static fn(Container $app): BingAdsClientInterface => BingAdsClientFactory::create(
                $app->make(CacheManager::class),
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
            BingAdsClientInterface::class,
        ];
    }
}
