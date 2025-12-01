<?php

declare(strict_types=1);

namespace App\Application\Contracts\Linnworks;

use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\ResourceNotFoundException;
use App\Domain\Inventory\ValueObjects\StockItem;

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
     */
    public function getStockItemBySku(string $sku): StockItem;
}
