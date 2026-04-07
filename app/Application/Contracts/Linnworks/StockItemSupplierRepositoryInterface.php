<?php

declare(strict_types=1);

namespace App\Application\Contracts\Linnworks;

use App\Domain\Catalog\Product\ValueObjects\ProductSupplier;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

/**
 * Repository for Linnworks stock item supplier persistence.
 *
 * Owns the linnworks.stock_item_suppliers table for targeted updates
 * (as opposed to the full delete/re-insert done by StockItemRepository during sync).
 */
interface StockItemSupplierRepositoryInterface
{
    /**
     * Bulk update supplier purchase prices for multiple SKUs.
     *
     * Resolves SKU → stock_item_id internally, then updates the matching
     * supplier row by name. SKUs not found locally are silently skipped.
     *
     * @param array<string, float> $purchasePricesBySku SKU → net purchase price
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function bulkUpdatePurchasePrices(string $supplierName, array $purchasePricesBySku): void;

    /**
     * Get all suppliers for the given SKUs, grouped by SKU.
     *
     * @param list<string> $skus
     *
     * @return array<string, list<ProductSupplier>> SKU → suppliers
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function getSuppliersBySkus(array $skus): array;
}
