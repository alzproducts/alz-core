<?php

declare(strict_types=1);

namespace App\Application\Contracts\Linnworks;

use App\Application\Contracts\RepositoryWriteInterface;
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
     * Bulk update supplier purchase prices for multiple SKUs.
     *
     * Matches by SKU and supplier name via a single SQL statement.
     * SKUs not found locally are silently skipped (local DB may be out of sync).
     *
     * @param array<string, float> $purchasePricesBySku SKU → net purchase price
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function bulkUpdateSupplierPurchasePrices(string $supplierName, array $purchasePricesBySku): void;
}
