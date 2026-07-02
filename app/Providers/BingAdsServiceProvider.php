<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\AdSpend\UseCases\SyncAdSpendUseCase;
use App\Application\Contracts\BingAdsClientInterface;
use App\Application\Contracts\BingAdsConversionInterface;
use App\Application\Contracts\LockableCacheInterface;
use App\Application\Contracts\MixpanelClientInterface;
use App\Infrastructure\BingAds\BingAdsClientFactory;
use App\Infrastructure\BingAds\BingAdsConfig;
use App\Infrastructure\BingAds\BingAdsConversionClient;
use App\Infrastructure\BingAds\BingAdsConversionService;
use App\Infrastructure\BingAds\BingAdsSessionManager;
use App\Infrastructure\Jobs\Mixpanel\SyncBingAdsToMixpanelJob;
use App\Infrastructure\Phone\PhoneNormalisationService;
use App\Infrastructure\Support\TransientLogThrottle;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Override;
use Psr\Log\LoggerInterface;

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
 *
 * Strategy pattern support: Contextual bindings wire Bing Ads jobs
 * to receive use cases configured with the Bing Ads client.
 */
final class BingAdsServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Register Bing Ads client and contextual bindings.
     *
     * Configuration and currency validation happens when the service
     * is first resolved via BingAdsClientFactory.
     */
    #[Override]
    public function register(): void
    {
        $this->registerSessionManager();
        $this->registerClient();
        $this->registerConversionClient();
        $this->registerContextualBindings();
    }

    private function registerSessionManager(): void
    {
        $this->app->singleton(
            BingAdsSessionManager::class,
            static fn(Container $app): BingAdsSessionManager => new BingAdsSessionManager(
                self::createConfig(),
                $app->make(LockableCacheInterface::class),
            ),
        );
    }

    private function registerClient(): void
    {
        $this->app->singleton(
            BingAdsClientInterface::class,
            static fn(Container $app): BingAdsClientInterface => BingAdsClientFactory::create(
                $app->make(BingAdsSessionManager::class),
                $app->make(TransientLogThrottle::class),
            ),
        );
    }

    private function registerConversionClient(): void
    {
        $this->registerConversionConfig();
        $this->registerConversionClientBinding();
        $this->registerConversionServiceBinding();
    }

    /**
     * Shared singleton — Client and Service receive the same BingAdsConfig instance,
     * so config + goal-name validation runs once per resolution. SOAP `BingAdsSessionManager`
     * builds its own config inline (not via container), so this binding is reserved for
     * the conversion variant.
     */
    private function registerConversionConfig(): void
    {
        $this->app->singleton(
            BingAdsConfig::class,
            static fn(): BingAdsConfig => BingAdsClientFactory::createConversionConfig(),
        );
    }

    private function registerConversionClientBinding(): void
    {
        $this->app->singleton(
            BingAdsConversionClient::class,
            static fn(Container $app): BingAdsConversionClient => BingAdsClientFactory::createConversionClient(
                $app->make(BingAdsSessionManager::class),
                $app->make(BingAdsConfig::class),
                $app->make(TransientLogThrottle::class),
            ),
        );
    }

    private function registerConversionServiceBinding(): void
    {
        $this->app->singleton(
            BingAdsConversionInterface::class,
            static fn(Container $app): BingAdsConversionInterface => new BingAdsConversionService(
                $app->make(BingAdsConversionClient::class),
                $app->make(BingAdsConfig::class),
                $app->make(PhoneNormalisationService::class),
            ),
        );
    }

    private function registerContextualBindings(): void
    {
        $this->app->when(SyncBingAdsToMixpanelJob::class)
            ->needs(SyncAdSpendUseCase::class)
            ->give(
                /**
                 * @throws BindingResolutionException
                 */
                static fn(Container $app): SyncAdSpendUseCase => new SyncAdSpendUseCase(
                    $app->make(BingAdsClientInterface::class),
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
            BingAdsClientInterface::class,
            BingAdsConversionClient::class,
            BingAdsConversionInterface::class,
            BingAdsSessionManager::class,
        ];
    }

    /**
     * Create BingAdsConfig from Laravel configuration.
     *
     * BingAdsConfig constructor handles validation (fail-fast for invalid config).
     */
    private static function createConfig(): BingAdsConfig
    {
        return new BingAdsConfig(
            clientId: Config::string('bing-ads.client_id', ''),
            clientSecret: Config::string('bing-ads.client_secret', ''),
            refreshToken: Config::string('bing-ads.refresh_token', ''),
            developerToken: Config::string('bing-ads.developer_token', ''),
            accountId: Config::string('bing-ads.account_id', ''),
            customerId: Config::string('bing-ads.customer_id', ''),
            environment: Config::string('bing-ads.environment', 'Production'),
            reportPollIntervalSeconds: Config::integer('bing-ads.report_poll_interval_seconds', 10),
            reportPollMaxAttempts: Config::integer('bing-ads.report_poll_max_attempts', 30),
        );
    }
}
