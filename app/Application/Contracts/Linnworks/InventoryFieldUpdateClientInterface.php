<?php

declare(strict_types=1);

namespace App\Application\Contracts\Linnworks;

use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Inventory\ValueObjects\InventoryFieldUpdate;
use App\Domain\ValueObjects\Guid;

/**
 * Linnworks inventory field update operations.
 *
 * Accepts either SKU or GUID as identifier:
 * - SKU: Resolved to stockItemId via resolveStockItemId() (extra API call)
 * - GUID: Used directly as stockItemId (no resolution, optimal for bulk)
 *
 * Each InventoryFieldUpdate results in one API call — Linnworks
 * UpdateInventoryItemField accepts one field per request.
 */
interface InventoryFieldUpdateClientInterface
{
    /**
     * @param Sku|Guid $identifier Current SKU (resolved internally) or stockItemId (used directly)
     * @param InventoryFieldUpdate ...$updates Fields to update — each triggers one API call
     *
     * @throws ResourceNotFoundException When stock item not found by SKU
     * @throws InvalidApiRequestException When parameters invalid
     * @throws InvalidApiResponseException When API response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function updateFields(Sku|Guid $identifier, InventoryFieldUpdate ...$updates): void;
}
