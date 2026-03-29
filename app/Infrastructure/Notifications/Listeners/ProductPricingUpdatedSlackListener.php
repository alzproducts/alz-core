<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Listeners;

use App\Application\Contracts\ChatNotificationInterface;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Contracts\Shopwired\SaleSettingsRepositoryInterface;
use App\Application\Notifications\DTOs\PriceUpdateAlertDataDTO;
use App\Domain\Catalog\Product\Events\ProductPricingUpdatedEvent;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\InvalidConfigurationException;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends Slack notification when product prices are updated.
 *
 * Enriches the notification with product title and URL from the database.
 * For add-to-sale events, reads SaleSettings from DB for the sale context block.
 * For removal events, uses the SaleSubmissionContext snapshot from the event.
 * Falls back gracefully if the product lookup fails.
 */
final class ProductPricingUpdatedSlackListener implements ShouldQueue
{
    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        private readonly ChatNotificationInterface $chat,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SaleSettingsRepositoryInterface $saleSettingsRepo,
    ) {}

    /**
     * @throws InvalidConfigurationException When Slack channel is not configured
     * @throws ExternalServiceUnavailableException On Slack delivery failure
     * @throws DatabaseOperationFailedException On sale settings DB query failure
     * @throws DuplicateRecordException On sale settings DB constraint violation
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

        // For add-to-sale: read persisted settings from DB.
        // For removals: SaleSubmissionContext snapshot is in the event (DB row already deleted).
        $saleSettings = $event->saleSubmissionContext === null
            ? $this->saleSettingsRepo->findByProduct($event->productId)
            : null;

        $this->chat->sendPriceUpdateAlert(new PriceUpdateAlertDataDTO(
            productId: $event->productId,
            priceChanges: $event->priceChanges,
            productTitle: $productTitle,
            productUrl: $productUrl,
            saleSettings: $saleSettings,
            saleSubmissionContext: $event->saleSubmissionContext,
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
