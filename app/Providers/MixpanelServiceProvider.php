<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\MixpanelClientInterface;
use App\Infrastructure\AdSpend\Mixpanel\MixpanelClientFactory;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Mixpanel Service Provider
 *
 * Deferred provider for MixpanelClient. Service is only loaded when requested,
 * allowing other features to function even if Mixpanel configuration is missing.
 * Configuration validation is handled by MixpanelClientFactory.
 */
final class MixpanelServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /**
     * Register Mixpanel client.
     *
     * Configuration is validated by MixpanelClientFactory when the service
     * is first resolved.
     */
    #[Override]
    public function register(): void
    {
        $this->app->singleton(MixpanelClientInterface::class, static fn(): MixpanelClientInterface => MixpanelClientFactory::create());
    }

    /**
     * Get the services provided by the provider.
     *
     * @return list<class-string>
     */
    #[Override]
    public function provides(): array
    {
        return [MixpanelClientInterface::class];
    }
}
