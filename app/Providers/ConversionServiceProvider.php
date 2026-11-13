<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\Conversion\ConversionDispatcherInterface;
use App\Application\Conversion\Enums\AdPlatform;
use App\Application\Conversion\Services\AdPlatformAdapterResolverService;
use App\Infrastructure\BingAds\BingAdsConversionAdapter;
use App\Infrastructure\Conversion\Dispatchers\QueuedConversionDispatcher;
use App\Infrastructure\Conversion\StandIns\StandInAdPlatformConversionAdapter;
use App\Infrastructure\GoogleAds\GoogleAdsConversionAdapter;
use App\Infrastructure\Integrations\IntegrationCredentials;
use App\Infrastructure\Integrations\StandInCallLogger;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Conversion Service Provider.
 *
 * Deferred provider for offline-conversion tracking. Binds the platform-agnostic
 * conversion dispatcher and the ad-platform adapter resolver (with its explicit
 * Google + Bing adapter list); lives in its own bounded context rather than under
 * any single ad-platform provider.
 */
final class ConversionServiceProvider extends ServiceProvider implements DeferrableProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->singleton(
            ConversionDispatcherInterface::class,
            QueuedConversionDispatcher::class,
        );

        $this->registerAdapterResolver();
    }

    /**
     * @return list<class-string>
     */
    #[Override]
    public function provides(): array
    {
        return [
            ConversionDispatcherInterface::class,
            AdPlatformAdapterResolverService::class,
        ];
    }

    /**
     * The resolver holds one adapter per ad platform; the list is explicit (not
     * container-tagged) so the fan-out order and membership are visible here.
     */
    private function registerAdapterResolver(): void
    {
        $this->app->singleton(
            AdPlatformAdapterResolverService::class,
            static fn(Container $app): AdPlatformAdapterResolverService => new AdPlatformAdapterResolverService([
                IntegrationCredentials::areSet(IntegrationCredentials::GOOGLE_ADS)
                    ? $app->make(GoogleAdsConversionAdapter::class)
                    : self::standInAdapter($app, AdPlatform::Google),
                IntegrationCredentials::areSet(IntegrationCredentials::BING_ADS)
                    ? $app->make(BingAdsConversionAdapter::class)
                    : self::standInAdapter($app, AdPlatform::Bing),
            ]),
        );
    }

    private static function standInAdapter(Container $app, AdPlatform $platform): StandInAdPlatformConversionAdapter
    {
        return new StandInAdPlatformConversionAdapter($platform, $app->make(StandInCallLogger::class));
    }
}
