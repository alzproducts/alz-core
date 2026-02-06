<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\AdSpend\UseCases\SyncAdSpendUseCase;
use App\Application\Contracts\GoogleAdsClientInterface;
use App\Application\Contracts\MixpanelClientInterface;
use App\Application\Jobs\Mixpanel\SyncCampaignLookupTableJob;
use App\Application\Jobs\Mixpanel\SyncGoogleAdsToMixpanelJob;
use App\Application\Mixpanel\UseCases\SyncLookupTableUseCase;
use App\Infrastructure\GoogleAds\GoogleAdsClientFactory;
use App\Infrastructure\Mixpanel\LookupTables\CampaignLookupTableProvider;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Override;
use Psr\Log\LoggerInterface;

/**
 * Google Ads Service Provider.
 *
 * Deferred provider for Google Ads client. Service is only loaded when requested,
 * allowing other features to function even if Google Ads configuration is missing.
 * Configuration validation is handled by GoogleAdsClientFactory.
 *
 * Strategy pattern support: Contextual bindings wire Google Ads jobs
 * to receive use cases configured with the Google Ads client.
 * Future ad sources (Bing, Facebook) would add similar bindings in their providers.
 */
final class GoogleAdsServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Register Google Ads client and contextual bindings.
     *
     * Configuration is validated by GoogleAdsClientFactory when the service
     * is first resolved.
     */
    #[Override]
    public function register(): void
    {
        // Singleton binding for GoogleAdsClientInterface (used by VerifyApiConnectivityCommand)
        $this->app->singleton(
            GoogleAdsClientInterface::class,
            static fn(): GoogleAdsClientInterface => GoogleAdsClientFactory::create(),
        );

        // Contextual binding: SyncGoogleAdsToMixpanelJob gets SyncAdSpendUseCase with Google client
        $this->app->when(SyncGoogleAdsToMixpanelJob::class)
            ->needs(SyncAdSpendUseCase::class)
            ->give(
                /**
                 * @throws BindingResolutionException
                 */
                static fn(Container $app): SyncAdSpendUseCase => new SyncAdSpendUseCase(
                    $app->make(GoogleAdsClientInterface::class),
                    $app->make(MixpanelClientInterface::class),
                    $app->make(LoggerInterface::class),
                ),
            );

        // Contextual binding: SyncCampaignLookupTableJob gets SyncLookupTableUseCase with CampaignLookupTableProvider
        $this->app->when(SyncCampaignLookupTableJob::class)
            ->needs(SyncLookupTableUseCase::class)
            ->give(
                /**
                 * @throws BindingResolutionException
                 */
                static fn(Container $app): SyncLookupTableUseCase => new SyncLookupTableUseCase(
                    new CampaignLookupTableProvider(
                        $app->make(GoogleAdsClientInterface::class),
                    ),
                    $app->make(MixpanelClientInterface::class),
                    $app->make(LoggerInterface::class),
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
            GoogleAdsClientInterface::class,
        ];
    }
}
