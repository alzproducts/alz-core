<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Listeners;

use App\Application\Contracts\ChatNotificationInterface;
use App\Domain\Catalog\Product\Events\ProductPricingUpdatedEvent;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidConfigurationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends Slack notification when product prices are updated.
 */
final class ProductPricingUpdatedSlackListener implements ShouldQueue
{
    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        private readonly ChatNotificationInterface $chat,
    ) {}

    /**
     * @throws InvalidConfigurationException When Slack channel is not configured
     * @throws ExternalServiceUnavailableException On Slack delivery failure
     */
    public function handle(ProductPricingUpdatedEvent $event): void
    {
        $this->chat->sendPriceUpdateAlert(
            productId: $event->productId,
            priceChanges: $event->priceChanges,
        );
    }

    public function failed(ProductPricingUpdatedEvent $event, Throwable $e): void
    {
        Log::error('Could not send product pricing update notification', [
            'product_id' => $event->productId->value,
            'exception' => $e->getMessage(),
        ]);
    }
}
