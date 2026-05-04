<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Clients;

use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Application\Contracts\Linnworks\InventoryFieldUpdateClientInterface;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Inventory\Enums\InventoryUpdatableField;
use App\Domain\Inventory\Enums\LinnworksInventoryField;
use App\Domain\Inventory\ValueObjects\InventoryFieldUpdate;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Linnworks\Contracts\LinnworksTransportInterface;
use App\Infrastructure\Linnworks\Enums\LinnworksLocation;

/**
 * Type-safe field updates for Linnworks inventory items.
 *
 * Resolves the stock item identifier once, then issues one API call per
 * field update (Linnworks accepts one field at a time).
 * Maps domain field enums to Linnworks API field names via exhaustive match.
 * PHPStan validates match exhaustiveness when new enum cases are added.
 *
 * Endpoint routing (Linnworks splits this across two endpoints by field scope):
 *
 * - **Location-scoped fields** (MinimumLevel, JIT, BinRack):
 *   `/api/Inventory/UpdateInventoryItemLocationField`, **camelCase** params
 *   (`inventoryItemId`, `fieldName`, `fieldValue`, `locationId`). Default
 *   warehouse GUID is used when no locationId is supplied.
 *
 * - **Item-level fields** (Title, Category, Barcode, Weight, RetailPrice,
 *   PurchasePrice): `/api/Inventory/UpdateInventoryItemField`, **PascalCase**
 *   params (`InventoryItemId`, `FieldName`, `FieldValue`). The endpoint
 *   rejects location-scoped fields with "field requiring location" and rejects
 *   item-level fields on the location endpoint with "field requiring
 *   operationPlace".
 *
 * This client currently only routes to the location-scoped endpoint, since
 * V2 only exposes MinimumLevel/JIT. When item-level fields are added (e.g.
 * Title, Category), introduce a `match($update->field)` to pick endpoint +
 * param casing per field.
 *
 * @template-pattern Infrastructure API Client
 */
final readonly class InventoryFieldUpdateClient implements InventoryFieldUpdateClientInterface
{
    public function __construct(
        private LinnworksTransportInterface $transport,
        private InventoryClientInterface $inventoryClient,
    ) {}

    /**
     * {@inheritDoc}
     *
     * Calls /api/Inventory/UpdateInventoryItemLocationField (camelCase params).
     * Currently supports location-scoped fields only — see class docblock for
     * the routing strategy when item-level field support is added.
     *
     * @throws ResourceNotFoundException When stock item not found by SKU
     * @throws InvalidApiRequestException When parameters invalid
     * @throws InvalidApiResponseException When API response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function updateFields(Sku|Guid $identifier, ?Guid $locationId = null, InventoryFieldUpdate ...$updates): void
    {
        if ($updates === []) {
            return;
        }

        $stockItemId = $this->inventoryClient->resolveStockItemId($identifier);
        $resolvedLocationId = $locationId !== null ? $locationId->value : LinnworksLocation::Default->value;

        foreach ($updates as $update) {
            $this->transport->postFormParams(
                endpoint: '/api/Inventory/UpdateInventoryItemLocationField',
                params: [
                    'inventoryItemId' => $stockItemId->value,
                    'fieldName' => self::mapField($update->field)->value,
                    'fieldValue' => $update->value,
                    'locationId' => $resolvedLocationId,
                ],
            );
        }
    }

    private static function mapField(InventoryUpdatableField $field): LinnworksInventoryField
    {
        return match ($field) {
            InventoryUpdatableField::Category => LinnworksInventoryField::Category,
            InventoryUpdatableField::MinimumLevel => LinnworksInventoryField::MinimumLevel,
            InventoryUpdatableField::JIT => LinnworksInventoryField::JIT,
            InventoryUpdatableField::RetailPrice => LinnworksInventoryField::RetailPrice,
            InventoryUpdatableField::PurchasePrice => LinnworksInventoryField::PurchasePrice,
            InventoryUpdatableField::BinRack => LinnworksInventoryField::BinRack,
            InventoryUpdatableField::Barcode => LinnworksInventoryField::Barcode,
            InventoryUpdatableField::Weight => LinnworksInventoryField::Weight,
            InventoryUpdatableField::Title => LinnworksInventoryField::Title,
        };
    }
}
