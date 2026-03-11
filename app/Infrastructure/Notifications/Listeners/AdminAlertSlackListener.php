<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Listeners;

use App\Domain\Notifications\Events\AdminAlertEvent;
use App\Infrastructure\Notifications\Slack\AdminAlertNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Sends a Slack admin alert when AdminAlertEvent is fired.
 *
 * Only sends if SLACK_ADMIN_ALERTS_CHANNEL is configured.
 * Queued independently so notification failures never affect the triggering operation.
 */
final class AdminAlertSlackListener implements ShouldQueue
{
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 1200];

    public function handle(AdminAlertEvent $event): void
    {
        $channel = \config('services.slack.notifications.admin_alerts_channel');
        if (! \is_string($channel) || $channel === '') {
            return;
        }

        Notification::route('slack', $channel)
            ->notify(new AdminAlertNotification($event->title, $event->message, $event->context, $event->firedAt));
    }

    public function failed(AdminAlertEvent $event, Throwable $e): void
    {
        Log::error('Could not send admin alert Slack notification', [
            'title' => $event->title,
            'exception' => $e->getMessage(),
        ]);
    }
}
