<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Application\Catalog\Enums\CreditTier;
use App\Application\Catalog\Enums\MarginTier;
use App\Domain\Catalog\Product\Commands\SetFreeDeliveryCommand;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;

/**
 * Dispatch ShopWired entity synchronisation tasks.
 *
 * Application layer uses this to trigger async sync without
 * knowing the delivery mechanism (queue, inline, etc.).
 */
interface ShopwiredSyncDispatcherInterface
{
    public function dispatchOrderSync(IntId $entityId): void;

    public function dispatchProductSync(IntId $entityId): void;

    public function dispatchCustomerSync(IntId $entityId): void;

    public function dispatchBrandSync(IntId $entityId): void;

    public function dispatchCategorySync(IntId $entityId): void;

    public function dispatchOrdersRangeSync(DateTimeImmutable $from, DateTimeImmutable $to): void;

    /**
     * Dispatch a full product catalogue sync from ShopWired.
     *
     * Deduplicated by ShouldBeUnique on the underlying job — a dispatch while
     * another run is in flight is a silent no-op.
     */
    public function dispatchAllProductsSync(): void;

    public function dispatchFreeDeliveryUpdate(SetFreeDeliveryCommand $command): void;

    public function dispatchReconcileComparePrice(IntId $productId): void;

    /**
     * Dispatch a job to add and/or remove a product from one or more ShopWired
     * categories in a single PUT. Empty arrays are valid — the receiving use
     * case performs its own idempotency check against live product state.
     *
     * @param list<IntId> $addCategoryIds    Categories to add
     * @param list<IntId> $removeCategoryIds Categories to remove
     */
    public function dispatchCategoryMembershipUpdate(
        IntId $productId,
        array $addCategoryIds,
        array $removeCategoryIds,
    ): void;

    /**
     * Dispatch a job to update the related_products custom field for a product.
     *
     * @param list<IntId> $relatedProductIds Ordered list of related product external IDs
     */
    public function dispatchRelatedProductsUpdate(
        IntId $productId,
        array $relatedProductIds,
    ): void;

    /**
     * Dispatch a job to set the Best Sellers label on a product's custom_label_4 field.
     *
     * @param string|null $label Label value, or null to clear the field.
     */
    public function dispatchBestSellerLabelUpdate(IntId $productId, ?string $label): void;

    /**
     * Dispatch a job to set the margin-tier label on a product's custom_label_1 field.
     */
    public function dispatchMarginTierLabelUpdate(IntId $productId, MarginTier $label): void;

    /**
     * Dispatch a job to set the credit-tier label on a product's custom_label_0 field.
     *
     * `null` tier clears the field (product has no credit sales in the latest snapshot).
     */
    public function dispatchCreditTierLabelUpdate(IntId $productId, ?CreditTier $tier): void;
}
