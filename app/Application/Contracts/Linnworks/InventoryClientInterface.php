<?php

declare(strict_types=1);

namespace App\Application\Contracts\Linnworks;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Inventory\ValueObjects\StockItem;
use App\Domain\Inventory\ValueObjects\StockItemFull;
use Generator;

/**
 * Contract for Linnworks inventory operations.
 *
 * @template-pattern Application Contract Interface
 */
interface InventoryClientInterface
{
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
}
