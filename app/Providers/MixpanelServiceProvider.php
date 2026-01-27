<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\MixpanelClientInterface;
use App\Application\Contracts\Shopwired\CustomerRepositoryInterface;
use App\Application\Contracts\Shopwired\OrderRepositoryInterface;
use App\Application\Mixpanel\UseCases\SyncLookupTableUseCase;
use App\Application\Mixpanel\UseCases\SyncOrdersToMixpanelUseCase;
use App\Infrastructure\Database\DatabaseGateway;
use App\Infrastructure\Mixpanel\LookupTables\OrderLookupTableProvider;
use App\Infrastructure\Mixpanel\LookupTables\ProductLookupTableProvider;
use App\Infrastructure\Mixpanel\MixpanelClientFactory;
use App\Infrastructure\Mixpanel\MixpanelConfig;
use App\Presentation\Jobs\Mixpanel\SyncOrderLookupTableJob;
use App\Presentation\Jobs\Mixpanel\SyncProductLookupTableJob;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Override;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Mixpanel Service Provider
 *
 * Deferred provider for MixpanelClient and Mixpanel use cases.
 * Services are only loaded when requested, allowing other features to function
 * even if Mixpanel configuration is missing.
 */
final class MixpanelServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Register Mixpanel services.
     *
     * Configuration is validated by MixpanelClientFactory when the service
     * is first resolved.
     */
    #[Override]
    public function register(): void
    {
        $this->app->singleton(MixpanelConfig::class, static fn(): MixpanelConfig => MixpanelClientFactory::createConfig());

        $this->app->singleton(MixpanelClientInterface::class, static fn(): MixpanelClientInterface => MixpanelClientFactory::create());

        $this->app->singleton(SyncOrdersToMixpanelUseCase::class, function (): SyncOrdersToMixpanelUseCase {
            $analyticsSalt = \config('mixpanel.analytics_salt');

            if (!\is_string($analyticsSalt) || $analyticsSalt === '') {
                throw new RuntimeException(
                    'ANALYTICS_SALT environment variable is required for Mixpanel order sync. '
                    . 'This salt must match the frontend for order_id_hashed deduplication.',
                );
            }

            return new SyncOrdersToMixpanelUseCase(
                orderRepository: $this->app->make(OrderRepositoryInterface::class),
                customerRepository: $this->app->make(CustomerRepositoryInterface::class),
                mixpanel: $this->app->make(MixpanelClientInterface::class),
                analyticsSalt: $analyticsSalt,
                logger: $this->app->make(LoggerInterface::class),
            );
        });

        // Contextual binding: SyncOrderLookupTableJob gets SyncLookupTableUseCase with OrderLookupTableProvider
        $this->app->when(SyncOrderLookupTableJob::class)
            ->needs(SyncLookupTableUseCase::class)
            ->give(
                /**
                 * @throws BindingResolutionException
                 */
                static fn(Container $app): SyncLookupTableUseCase => new SyncLookupTableUseCase(
                    new OrderLookupTableProvider(
                        $app->make(MixpanelConfig::class),
                        $app->make(DatabaseGateway::class),
                    ),
                    $app->make(MixpanelClientInterface::class),
                    $app->make(LoggerInterface::class),
                ),
            );

        // Contextual binding: SyncProductLookupTableJob gets SyncLookupTableUseCase with ProductLookupTableProvider
        $this->app->when(SyncProductLookupTableJob::class)
            ->needs(SyncLookupTableUseCase::class)
            ->give(
                /**
                 * @throws BindingResolutionException
                 */
                static fn(Container $app): SyncLookupTableUseCase => new SyncLookupTableUseCase(
                    new ProductLookupTableProvider(
                        $app->make(DatabaseGateway::class),
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
            MixpanelConfig::class,
            MixpanelClientInterface::class,
            SyncOrdersToMixpanelUseCase::class,
        ];
    }
}
