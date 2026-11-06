<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\Conversion\ConversionDispatcherInterface;
use App\Application\Conversion\Enums\AdPlatform;
use App\Application\Conversion\Services\AdPlatformAdapterResolverService;
use App\Infrastructure\BingAds\BingAdsConversionAdapter;
use App\Infrastructure\Conversion\Dispatchers\QueuedConversionDispatcher;
use App\Infrastructure\Conversion\Fakes\RecordingAdPlatformConversionAdapter;
use App\Infrastructure\GoogleAds\GoogleAdsConversionAdapter;
use App\Infrastructure\Integrations\LiveIntegration;
use App\Infrastructure\Integrations\LiveIntegrationGate;
use App\Infrastructure\Integrations\OutboundCallRecorder;
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
            static function (Container $app): AdPlatformAdapterResolverService {
                $gate = $app->make(LiveIntegrationGate::class);

                return new AdPlatformAdapterResolverService([
                    $gate->isLive(LiveIntegration::GoogleAds)
                        ? $app->make(GoogleAdsConversionAdapter::class)
                        : self::recordingAdapter($app, AdPlatform::Google),
                    $gate->isLive(LiveIntegration::BingAds)
                        ? $app->make(BingAdsConversionAdapter::class)
                        : self::recordingAdapter($app, AdPlatform::Bing),
                ]);
            },
        );
    }

    private static function recordingAdapter(Container $app, AdPlatform $platform): RecordingAdPlatformConversionAdapter
    {
        return new RecordingAdPlatformConversionAdapter($platform, $app->make(OutboundCallRecorder::class));
    }
}
