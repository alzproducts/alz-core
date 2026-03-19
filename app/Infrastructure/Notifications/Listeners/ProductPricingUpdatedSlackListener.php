<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Listeners;

use App\Domain\Catalog\Product\Events\ProductPricingUpdatedEvent;
use App\Infrastructure\Notifications\Slack\ProductPricingUpdatedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Sends Slack notification when product prices are updated.
 *
 * Only sends if SLACK_VERBOSE_CHANNEL is configured.
 */
final class ProductPricingUpdatedSlackListener implements ShouldQueue
{
    public int $tries = 3;

    public int $backoff = 60;

    public function handle(ProductPricingUpdatedEvent $event): void
    {
        $channel = \config('services.slack.notifications.verbose_channel');
        if (! \is_string($channel) || $channel === '') {
            return;
        }

        Notification::route('slack', $channel)
            ->notify(new ProductPricingUpdatedNotification(
                productId: $event->productId->value,
                priceChanges: $event->priceChanges,
            ));
    }

    public function failed(ProductPricingUpdatedEvent $event, Throwable $e): void
    {
        Log::error('Could not send product pricing update notification', [
            'product_id' => $event->productId->value,
            'exception' => $e->getMessage(),
        ]);
    }
}
