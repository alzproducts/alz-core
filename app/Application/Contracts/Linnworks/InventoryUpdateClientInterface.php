<?php

declare(strict_types=1);

namespace App\Application\Contracts\Linnworks;

use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Inventory\Commands\AddInventoryItemCommand;
use App\Domain\Inventory\ValueObjects\ExtendedPropertyWrite;
use App\Domain\Shared\Money\ValueObjects\Money;
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

    /**
     * Create a new inventory item.
     *
     * Generates a new stockItemId (UUID) and creates the item in Linnworks.
     *
     * @param Guid $categoryId Category to assign the item to
     * @param AddInventoryItemCommand $command Business data for the new item
     * @return Guid The generated stockItemId
     *
     * @throws ResourceNotFoundException When category not found
     * @throws InvalidApiRequestException When parameters invalid
     * @throws InvalidApiResponseException When API response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function addInventoryItem(Guid $categoryId, AddInventoryItemCommand $command): Guid;

    /**
     * Link a supplier to a stock item.
     *
     * @param Sku|Guid $identifier SKU (resolved internally) or stockItemId (used directly)
     * @param Guid $supplierId The supplier to link
     * @param Money|null $purchasePrice Cost price from this supplier (null = unknown)
     * @param string|null $supplierCode Supplier's code/SKU for this item
     * @param bool $isDefault Whether this is the default supplier
     *
     * @throws ResourceNotFoundException When stock item or supplier not found
     * @throws InvalidApiRequestException When parameters invalid
     * @throws InvalidApiResponseException When API response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function createSupplierStat(
        Sku|Guid $identifier,
        Guid $supplierId,
        ?Money $purchasePrice,
        ?string $supplierCode = null,
        bool $isDefault = false,
    ): void;

    /**
     * Add an extended property to a stock item.
     *
     * @param Sku|Guid $identifier SKU (resolved internally) or stockItemId (used directly)
     * @param string $name Property name (e.g., "ShopID")
     * @param string $value Property value
     *
     * @throws ResourceNotFoundException When stock item not found
     * @throws InvalidApiRequestException When parameters invalid
     * @throws InvalidApiResponseException When API response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function addExtendedProperty(Sku|Guid $identifier, string $name, string $value): void;

    /**
     * Set extended properties on a stock item (create or update).
     *
     * Reads current EPs, compares values, and only writes when different:
     * - Existing EP with different value → update
     * - Missing EP → create
     * - Existing EP with same value → skip (no write)
     *
     * Accepts multiple properties to avoid repeated reads.
     *
     * @param Sku|Guid $identifier SKU (resolved internally) or stockItemId (used directly)
     * @param list<ExtendedPropertyWrite> $properties EPs to set
     *
     * @throws ResourceNotFoundException When stock item not found
     * @throws InvalidApiRequestException When parameters invalid
     * @throws InvalidApiResponseException When API response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function setExtendedProperties(Sku|Guid $identifier, array $properties): void;

    /**
     * Add an image to a stock item.
     *
     * @param Sku|Guid $identifier SKU (resolved internally) or stockItemId (used directly)
     * @param string $imageUrl URL of the image to add
     *
     * @throws ResourceNotFoundException When stock item not found
     * @throws InvalidApiRequestException When parameters invalid or URL unreachable
     * @throws InvalidApiResponseException When API response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function addImage(Sku|Guid $identifier, string $imageUrl): void;

    /**
     * Delete an inventory item.
     *
     * Used for rollback when item creation partially fails.
     *
     * @param Sku|Guid $identifier SKU (resolved internally) or stockItemId (used directly)
     *
     * @throws ResourceNotFoundException When stock item not found
     * @throws InvalidApiRequestException When parameters invalid
     * @throws InvalidApiResponseException When API response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function deleteInventoryItem(Sku|Guid $identifier): void;
}
