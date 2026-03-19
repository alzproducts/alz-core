<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Listeners;

use App\Application\Contracts\ChatNotificationInterface;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Domain\Catalog\Product\Events\ProductPricingUpdatedEvent;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidConfigurationException;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends Slack notification when product prices are updated.
 *
 * Enriches the notification with product title and URL from the database.
 * Falls back gracefully if the product lookup fails.
 */
final class ProductPricingUpdatedSlackListener implements ShouldQueue
{
    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        private readonly ChatNotificationInterface $chat,
        private readonly ProductRepositoryInterface $productRepository,
    ) {}

    /**
     * @throws InvalidConfigurationException When Slack channel is not configured
     * @throws ExternalServiceUnavailableException On Slack delivery failure
     */
    public function handle(ProductPricingUpdatedEvent $event): void
    {
        $productTitle = null;
        $productUrl = null;

        try {
            $product = $this->productRepository->getProduct($event->productId);
            $productTitle = $product->title;
            $productUrl = $product->url;
        } catch (Exception $e) { // @ignoreException - enrichment is best-effort, notification still sends
            Log::warning('Could not enrich pricing notification with product details', [
                'product_id' => $event->productId->value,
                'exception' => $e->getMessage(),
            ]);
        }

        $this->chat->sendPriceUpdateAlert(
            productId: $event->productId,
            priceChanges: $event->priceChanges,
            productTitle: $productTitle,
            productUrl: $productUrl,
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
