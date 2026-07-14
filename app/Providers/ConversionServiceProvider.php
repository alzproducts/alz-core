<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\Conversion\ConversionDispatcherInterface;
use App\Application\Conversion\Services\AdPlatformAdapterResolverService;
use App\Infrastructure\BingAds\BingAdsConversionAdapter;
use App\Infrastructure\Conversion\Dispatchers\QueuedConversionDispatcher;
use App\Infrastructure\GoogleAds\GoogleAdsConversionAdapter;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Conversion Service Provider.
 *
 * Deferred provider for offline-conversion tracking. Binds the platform-agnostic
 * conversion dispatcher; lives in its own bounded context rather than under any
 * specific ad-platform provider — future fan-out (Bing, etc.) will register here.
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
                $app->make(GoogleAdsConversionAdapter::class),
                $app->make(BingAdsConversionAdapter::class),
            ]),
        );
    }
}
