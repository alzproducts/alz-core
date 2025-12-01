<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\GoogleAdsClientInterface;
use App\Infrastructure\GoogleAds\GoogleAdsClientFactory;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Google Ads Service Provider
 *
 * Deferred provider for GoogleAdsClient. Service is only loaded when requested,
 * allowing other features to function even if Google Ads configuration is missing.
 * Configuration validation is handled by GoogleAdsClientFactory.
 */
final class GoogleAdsServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Register Google Ads client.
     *
     * Configuration is validated by GoogleAdsClientFactory when the service
     * is first resolved.
     */
    #[Override]
    public function register(): void
    {
        $this->app->singleton(GoogleAdsClientInterface::class, static fn(): GoogleAdsClientInterface => GoogleAdsClientFactory::create());
    }

    /**
     * Get the services provided by the provider.
     *
     * @return list<class-string>
     */
    #[Override]
    public function provides(): array
    {
        return [GoogleAdsClientInterface::class];
    }
}
