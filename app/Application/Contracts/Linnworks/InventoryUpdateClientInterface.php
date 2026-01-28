<?php

declare(strict_types=1);

namespace App\Application\Contracts\Linnworks;

use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;

/**
 * Linnworks inventory update operations.
 *
 * Infrastructure resolves SKU → stockItemId internally via getStockItemBySku().
 */
interface InventoryUpdateClientInterface
{
    /**
     * Update the SKU (ItemNumber) for a stock item.
     *
     * Resolves the current SKU to Linnworks stockItemId internally.
     *
     * @param Sku $currentSku The existing SKU to find
     * @param Sku $newSku The new SKU value to set
     *
     * @throws ResourceNotFoundException When stock item not found by SKU
     * @throws InvalidApiRequestException When parameters invalid
     * @throws InvalidApiResponseException When API response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function updateSku(Sku $currentSku, Sku $newSku): void;
}
