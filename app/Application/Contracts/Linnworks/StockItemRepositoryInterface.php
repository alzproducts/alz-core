<?php

declare(strict_types=1);

namespace App\Application\Contracts\Linnworks;

use App\Application\Contracts\RepositoryWriteInterface;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Inventory\ValueObjects\StockItemFull;

/**
 * Repository for Linnworks stock item persistence.
 *
 * Sync strategy:
 * - Stock items: upsert by stock_item_id (Linnworks GUID)
 * - Extended properties: delete/re-insert (catches removals in Linnworks)
 *
 * @extends RepositoryWriteInterface<StockItemFull>
 */
interface StockItemRepositoryInterface extends RepositoryWriteInterface
{
    /**
     * Get all default supplier cost prices keyed by SKU.
     *
     * Joins stock_items with stock_item_suppliers (default supplier only)
     * to build a complete SKU → purchase_price map.
     *
     * @return array<string, float> SKU → purchase_price from default supplier
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function getCostPricesBySku(): array;

    /**
     * Update the purchase price for a specific supplier on a stock item.
     *
     * Matches by SKU and supplier name. If no row is found (local DB out of sync),
     * the update silently no-ops — callers should handle this case if needed.
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function updateSupplierPurchasePrice(Sku $sku, string $supplierName, float $purchasePrice): void;
}
