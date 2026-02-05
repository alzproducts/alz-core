<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Listeners;

use App\Domain\ContactSubmission\Events\ContactFormProcessedEvent;
use App\Infrastructure\Notifications\Slack\ContactFormProcessedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Sends Slack notification when contact form is successfully processed.
 *
 * Only sends if SLACK_VERBOSE_CHANNEL is configured.
 */
final class ContactFormProcessedSlackListener implements ShouldQueue
{
    public int $tries = 3;

    public int $backoff = 60;

    public function handle(ContactFormProcessedEvent $event): void
    {
        $channel = \config('services.slack.notifications.verbose_channel');
        if (! \is_string($channel) || $channel === '') {
            return;
        }

        Notification::route('slack', $channel)
            ->notify(new ContactFormProcessedNotification(
                conversationId: $event->conversationId->value,
                customerName: $event->customerName,
                customerEmail: $event->customerEmail,
            ));
    }

    public function failed(ContactFormProcessedEvent $event, Throwable $e): void
    {
        Log::error('Could not send success notification', [
            'submission_id' => $event->submissionId,
            'exception' => $e->getMessage(),
        ]);
    }
}
