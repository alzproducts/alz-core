<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\ChatNotificationInterface;
use App\Infrastructure\Notifications\SlackChatNotificationClient;
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
            SlackChatNotificationClient::class,
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
