<?php

declare(strict_types=1);

namespace App\Application\Shopwired\SaleManagement\Services;

use App\Domain\Catalog\Product\Events\ProductAddedToSaleEvent;
use App\Domain\Catalog\Product\Events\ProductPricingUpdatedEvent;
use App\Domain\Catalog\Product\Events\ProductRemovedFromSaleEvent;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Catalog\Product\ValueObjects\SkuPriceChange;
use Illuminate\Contracts\Events\Dispatcher;
use Webmozart\Assert\Assert;

/**
 * Detects sale state transitions from pricing events and dispatches sale-specific events.
 *
 * Inspects SkuPriceChange transitions on ProductPricingUpdatedEvent:
 * - Any SKU addedToSale() → dispatches ProductAddedToSaleEvent
 * - Any SKU removedFromSale() → dispatches ProductRemovedFromSaleEvent
 *
 * Lives in Application layer because it dispatches domain events (business logic),
 * not in Infrastructure which is only the delivery mechanism.
 */
final readonly class SaleStateDetectionService
{
    public function __construct(
        private Dispatcher $events,
    ) {}

    public function detectAndDispatch(ProductPricingUpdatedEvent $event): void
    {
        [$addedSku, $removedSku] = $this->findSaleTransitions($event->priceChanges);

        if ($addedSku === null && $removedSku === null) {
            return;
        }

        Assert::notNull(
            $event->saleSettings,
            "Sale state change detected for product {$event->productId->value} but no SaleSettings provided",
        );

        if ($addedSku !== null) {
            $this->events->dispatch(new ProductAddedToSaleEvent(
                productId: $event->productId,
                sku: $addedSku,
                saleSettings: $event->saleSettings,
            ));
        }

        if ($removedSku !== null) {
            $this->events->dispatch(new ProductRemovedFromSaleEvent(
                productId: $event->productId,
                sku: $removedSku,
                saleSettings: $event->saleSettings,
            ));
        }
    }

    /**
     * @param list<SkuPriceChange> $priceChanges
     *
     * @return array{Sku|null, Sku|null} [addedSku, removedSku]
     */
    private function findSaleTransitions(array $priceChanges): array
    {
        $addedSku = null;
        $removedSku = null;

        foreach ($priceChanges as $change) {
            if ($addedSku === null && $change->addedToSale()) {
                $addedSku = $change->sku;
            }

            if ($removedSku === null && $change->removedFromSale()) {
                $removedSku = $change->sku;
            }

            if ($addedSku !== null && $removedSku !== null) {
                break;
            }
        }

        return [$addedSku, $removedSku];
    }
}
