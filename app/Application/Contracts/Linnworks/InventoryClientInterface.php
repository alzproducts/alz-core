<?php

declare(strict_types=1);

namespace App\Application\Contracts\Linnworks;

use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Inventory\ValueObjects\StockItem;
use App\Domain\Inventory\ValueObjects\StockItemFull;
use App\Domain\Inventory\ValueObjects\Supplier;
use App\Domain\ValueObjects\Guid;
use Generator;

/**
 * Contract for Linnworks inventory operations.
 *
 * @template-pattern Application Contract Interface
 */
interface InventoryClientInterface
{
    /**
     * Resolve a SKU or GUID identifier to a Linnworks stockItemId.
     *
     * - GUID: Returned directly (no API call)
     * - SKU: Resolved via API lookup
     *
     * @throws ResourceNotFoundException When SKU doesn't exist in Linnworks
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function resolveStockItemId(Sku|Guid $identifier): Guid;

    /**
     * Retrieve a stock item by its SKU.
     *
     * @param string $sku The product SKU (ItemNumber in Linnworks)
     *
     * @throws ResourceNotFoundException When item doesn't exist
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function getStockItemBySku(string $sku): StockItem;

    /**
     * Iterate all stock items with extended properties in batches.
     *
     * Memory-efficient generator that fetches stock items from GetStockItemsFull
     * endpoint with ExtendedProperties included. Yields batches of ~200 items.
     *
     * Pagination: Uses entriesPerPage=200, pageNumber increments.
     * Stop condition: Empty result or fewer items than page size.
     *
     * @return Generator<int, list<StockItemFull>, mixed, void> Yields batches (page number as key)
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found (404)
     */
    public function iterateStockItemBatches(): Generator;

    /**
     * Generate a new sequential SKU from Linnworks.
     *
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiResponseException When response format unexpected
     */
    public function getNewItemNumber(): Sku;

    /**
     * Retrieve a full stock item (with extended properties) by identifier.
     *
     * Use this when you need extended properties, suppliers, or other data
     * not available from getStockItemBySku().
     *
     * @throws ResourceNotFoundException When stock item doesn't exist
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function getStockItemFull(Sku|Guid $identifier): StockItemFull;

    /**
     * Fetch all suppliers from the Linnworks master supplier directory.
     *
     * Returns the complete list of suppliers (small dataset, no pagination).
     *
     * @return list<Supplier>
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found (404)
     */
    public function getSuppliers(): array;
}
