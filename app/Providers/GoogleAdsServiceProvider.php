<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\AdSpend\UseCases\SyncAdSpendUseCase;
use App\Application\Contracts\GoogleAdsClientInterface;
use App\Application\Contracts\MixpanelClientInterface;
use App\Infrastructure\GoogleAds\GoogleAdsClientFactory;
use App\Presentation\Jobs\SyncGoogleAdsToMixpanelJob;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Override;
use Psr\Log\LoggerInterface;

/**
 * Google Ads Service Provider
 *
 * Deferred provider for GoogleAdsClient. Service is only loaded when requested,
 * allowing other features to function even if Google Ads configuration is missing.
 * Configuration validation is handled by GoogleAdsClientFactory.
 *
 * Strategy pattern support: Contextual binding wires SyncGoogleAdsToMixpanelJob
 * to receive a SyncAdSpendUseCase configured with the Google Ads client.
 * Future ad sources (Bing, Facebook) would add similar bindings in their providers.
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
        // Singleton binding for Google Ads-specific interface
        $this->app->singleton(
            GoogleAdsClientInterface::class,
            static fn(): GoogleAdsClientInterface => GoogleAdsClientFactory::create(),
        );

        // Contextual binding: When SyncGoogleAdsToMixpanelJob needs SyncAdSpendUseCase,
        // build it with the Google Ads client. This enables Strategy pattern where
        // each job type gets a use case wired with the appropriate ad source.
        $this->app->when(SyncGoogleAdsToMixpanelJob::class)
            ->needs(SyncAdSpendUseCase::class)
            ->give(static fn(Container $app): SyncAdSpendUseCase => new SyncAdSpendUseCase(
                $app->make(GoogleAdsClientInterface::class),
                $app->make(MixpanelClientInterface::class),
                $app->make(LoggerInterface::class),
            ));
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
            GoogleAdsClientInterface::class,
        ];
    }
}
