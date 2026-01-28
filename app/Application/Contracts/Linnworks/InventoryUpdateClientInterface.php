<?php

declare(strict_types=1);

namespace App\Application\Contracts\Linnworks;

use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\ValueObjects\Guid;

/**
 * Linnworks inventory update operations.
 *
 * Accepts either SKU or GUID as identifier:
 * - SKU: Resolved to stockItemId via getStockItemBySku() (extra API call)
 * - GUID: Used directly as stockItemId (no resolution needed)
 *
 * For bulk operations, prefer resolving SKUs to GUIDs upfront via
 * InventoryClientInterface, then passing GUIDs here to avoid N+1 API calls.
 */
interface InventoryUpdateClientInterface
{
    /**
     * Update the SKU (ItemNumber) for a stock item.
     *
     * @param Sku|Guid $identifier Current SKU (resolved internally) or stockItemId (used directly)
     * @param Sku $newSku The new SKU value to set
     *
     * @throws ResourceNotFoundException When stock item not found by SKU
     * @throws InvalidApiRequestException When parameters invalid
     * @throws InvalidApiResponseException When API response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function updateSku(Sku|Guid $identifier, Sku $newSku): void;
}
