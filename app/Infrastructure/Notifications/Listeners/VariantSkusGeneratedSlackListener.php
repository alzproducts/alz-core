<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Listeners;

use App\Domain\Inventory\Events\VariantSkusGeneratedEvent;
use App\Infrastructure\Notifications\Slack\VariantSkusGeneratedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Sends Slack notification when variant SKUs are generated.
 *
 * Only sends if SLACK_BOT_USER_DEFAULT_CHANNEL is configured.
 */
final class VariantSkusGeneratedSlackListener implements ShouldQueue
{
    public int $tries = 3;

    public int $backoff = 60;

    public function handle(VariantSkusGeneratedEvent $event): void
    {
        $channel = \config('services.slack.notifications.channel');
        if (! \is_string($channel) || $channel === '') {
            return;
        }

        Notification::route('slack', $channel)
            ->notify(new VariantSkusGeneratedNotification(
                productId: $event->productId,
                productTitle: $event->productTitle,
                created: $event->created,
                skipped: $event->skipped,
                failed: $event->failed,
                createdVariants: $event->createdVariants,
            ));
    }

    public function failed(VariantSkusGeneratedEvent $event, Throwable $e): void
    {
        Log::error('Could not send variant SKU generation notification', [
            'product_id' => $event->productId,
            'exception' => $e->getMessage(),
        ]);
    }
}
