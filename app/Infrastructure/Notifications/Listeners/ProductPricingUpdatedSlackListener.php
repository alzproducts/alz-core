<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Listeners;

use App\Application\Catalog\Queries\ProductDetailQueryParams;
use App\Application\Contracts\ChatNotificationInterface;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Contracts\Shopwired\SaleSettingsRepositoryInterface;
use App\Application\Notifications\DTOs\PriceUpdateAlertDataDTO;
use App\Domain\Catalog\Product\Events\ProductPricingUpdatedEvent;
use App\Domain\Catalog\Product\ValueObjects\SaleSettings;
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
        [$productTitle, $productUrl] = $this->enrichProductContext($event);

        $this->chat->sendPriceUpdateAlert(new PriceUpdateAlertDataDTO(
            productId: $event->productId,
            priceChanges: $event->priceChanges,
            productTitle: $productTitle,
            productUrl: $productUrl,
            saleSettings: $this->resolveSaleSettings($event),
            saleSubmissionContext: $event->saleSubmissionContext,
        ));
    }

    /**
     * Best-effort enrichment with product title and URL.
     *
     * @return array{string|null, string|null} [title, url]
     */
    private function enrichProductContext(ProductPricingUpdatedEvent $event): array
    {
        try {
            $view = $this->productRepository->findProductView(new ProductDetailQueryParams($event->productId));

            return [$view->title, $view->links->publicUrl];
        } catch (Exception $e) { // @ignoreException - enrichment is best-effort, notification still sends
            Log::warning('Could not enrich pricing notification with product details', [
                'product_id' => $event->productId->value,
                'exception' => $e->getMessage(),
            ]);

            return [null, null];
        }
    }

    /**
     * For add-to-sale: read persisted settings from DB.
     * For removals: SaleSubmissionContext snapshot is in the event (DB row already deleted).
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database unavailable
     */
    private function resolveSaleSettings(ProductPricingUpdatedEvent $event): ?SaleSettings
    {
        return $event->saleSubmissionContext === null
            ? $this->saleSettingsRepo->findByProduct($event->productId)
            : null;
    }

    public function failed(ProductPricingUpdatedEvent $event, Throwable $e): void
    {
        Log::error('Could not send product pricing update notification', [
            'product_id' => $event->productId->value,
            'exception' => $e->getMessage(),
        ]);
    }
}
