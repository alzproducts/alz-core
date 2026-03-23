<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\Catalog\Product\ValueObjects\SaleSettings;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\ValueObjects\IntId;

/**
 * Dispatcher for sale state reconciliation jobs.
 */
interface SaleReconciliationDispatcherInterface
{
    /**
     * Dispatch add-to-sale corrections for a product.
     */
    public function dispatchAddToSale(IntId $productId, SaleSettings $saleSettings, int $saleCategoryId): void;

    /**
     * Dispatch remove-from-sale corrections for a product.
     */
    public function dispatchRemoveFromSale(IntId $productId, int $saleCategoryId): void;

    /**
     * Dispatch a Linnworks is_in_sale EP update for a SKU.
     *
     * The job reads current sale state from the local DB to ensure idempotency.
     */
    public function dispatchUpdateSaleState(IntId $productId, Sku $sku): void;

    /**
     * Dispatch a delayed reconciliation check for a product.
     */
    public function dispatchReconciliation(IntId $productId, ?SaleSettings $saleSettings): void;
}
