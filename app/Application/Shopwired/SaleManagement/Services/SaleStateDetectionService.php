<?php

declare(strict_types=1);

namespace App\Application\Shopwired\SaleManagement\Services;

use App\Domain\Catalog\Product\Events\ProductAddedToSaleEvent;
use App\Domain\Catalog\Product\Events\ProductPricingUpdatedEvent;
use App\Domain\Catalog\Product\Events\ProductRemovedFromSaleEvent;
use App\Domain\Catalog\Product\Events\SkuAddedToSaleEvent;
use App\Domain\Catalog\Product\Events\SkuRemovedFromSaleEvent;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Catalog\Product\ValueObjects\SkuPriceChange;
use Illuminate\Contracts\Events\Dispatcher;
use Webmozart\Assert\Assert;

/**
 * Detects sale state transitions from pricing events and dispatches sale-specific events.
 *
 * Dispatches two tiers of events from ProductPricingUpdatedEvent:
 * - Product-level: one ProductAddedToSaleEvent/ProductRemovedFromSaleEvent per product
 *   (for ShopWired: category, custom fields, sort order)
 * - SKU-level: one SkuAddedToSaleEvent/SkuRemovedFromSaleEvent per variation
 *   (for Linnworks: per-SKU EP updates like is_in_sale)
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
        [$addedSkus, $removedSkus] = $this->findSaleTransitions($event->priceChanges);

        if ($addedSkus === [] && $removedSkus === []) {
            return;
        }

        Assert::notNull(
            $event->saleSettings,
            "Sale state change detected for product {$event->productId->value} but no SaleSettings provided",
        );

        // Product-level events (one per product) — for ShopWired side-effects
        if ($addedSkus !== []) {
            $this->events->dispatch(new ProductAddedToSaleEvent(
                productId: $event->productId,
                saleSettings: $event->saleSettings,
            ));
        }

        if ($removedSkus !== []) {
            $this->events->dispatch(new ProductRemovedFromSaleEvent(
                productId: $event->productId,
                saleSettings: $event->saleSettings,
            ));
        }

        // SKU-level events (one per variation) — for Linnworks EP updates
        foreach ($addedSkus as $sku) {
            $this->events->dispatch(new SkuAddedToSaleEvent(
                productId: $event->productId,
                sku: $sku,
            ));
        }

        foreach ($removedSkus as $sku) {
            $this->events->dispatch(new SkuRemovedFromSaleEvent(
                productId: $event->productId,
                sku: $sku,
            ));
        }
    }

    /**
     * @param list<SkuPriceChange> $priceChanges
     *
     * @return array{list<Sku>, list<Sku>} [addedSkus, removedSkus]
     */
    private function findSaleTransitions(array $priceChanges): array
    {
        /** @var list<Sku> $addedSkus */
        $addedSkus = [];
        /** @var list<Sku> $removedSkus */
        $removedSkus = [];

        foreach ($priceChanges as $change) {
            if ($change->addedToSale()) {
                $addedSkus[] = $change->sku;
            }

            if ($change->removedFromSale()) {
                $removedSkus[] = $change->sku;
            }
        }

        return [$addedSkus, $removedSkus];
    }
}
