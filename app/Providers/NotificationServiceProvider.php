<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\ChatNotificationInterface;
use App\Infrastructure\Integrations\LiveIntegration;
use App\Infrastructure\Integrations\LiveIntegrationGate;
use App\Infrastructure\Notifications\Fakes\RecordingChatNotification;
use App\Infrastructure\Notifications\SlackChatNotificationClient;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Notification Service Provider.
 *
 * Deferred provider for notification delivery bindings.
 * Services are only loaded when a listener or UseCase requests them.
 */
final class NotificationServiceProvider extends ServiceProvider implements DeferrableProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->bind(
            ChatNotificationInterface::class,
            static fn(Container $app): ChatNotificationInterface => $app->make(LiveIntegrationGate::class)->isLive(LiveIntegration::Slack)
                ? $app->make(SlackChatNotificationClient::class)
                : $app->make(RecordingChatNotification::class),
        );
    }

    /**
     * @return list<class-string>
     */
    #[Override]
    public function provides(): array
    {
        return [
            ChatNotificationInterface::class,
        ];
    }
}
