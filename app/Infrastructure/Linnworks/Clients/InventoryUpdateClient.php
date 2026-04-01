<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Clients;

use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Application\Contracts\Linnworks\InventoryUpdateClientInterface;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Inventory\Commands\AddInventoryItemCommand;
use App\Domain\Inventory\Enums\LinnworksInventoryField;
use App\Domain\Inventory\ValueObjects\ExtendedPropertyWrite;
use App\Domain\Inventory\ValueObjects\SupplierLinkParams;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Linnworks\Contracts\LinnworksTransportInterface;
use App\Infrastructure\Linnworks\Requests\UpdateStockSupplierStatRequest;
use Illuminate\Support\Str;

/**
 * Linnworks inventory update operations.
 *
 * Handles SKU updates with flexible identifier resolution:
 * - SKU: Resolved to stockItemId via getStockItemBySku() (extra API call)
 * - GUID: Used directly as stockItemId (no resolution, optimal for bulk)
 *
 * @template-pattern Infrastructure API Client
 */
final readonly class InventoryUpdateClient implements InventoryUpdateClientInterface
{
    public function __construct(
        private LinnworksTransportInterface $transport,
        private InventoryClientInterface $inventoryClient,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws ResourceNotFoundException When stock item not found by SKU
     * @throws InvalidApiRequestException When parameters invalid
     * @throws InvalidApiResponseException When API response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function updateSku(Sku|Guid $identifier, Sku $newSku): void
    {
        $stockItemId = $this->inventoryClient->resolveStockItemId($identifier);

        $this->transport->postFormParams(
            endpoint: '/api/Inventory/UpdateInventoryItemField',
            params: [
                'inventoryItemId' => $stockItemId->value,
                'fieldName' => LinnworksInventoryField::SKU->value,
                'fieldValue' => $newSku->value,
            ],
        );
    }

    /**
     * {@inheritDoc}
     *
     * @throws ResourceNotFoundException When category not found
     * @throws InvalidApiRequestException When parameters invalid
     * @throws InvalidApiResponseException When API response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function addInventoryItem(Guid $categoryId, AddInventoryItemCommand $command): Guid
    {
        $stockItemId = new Guid(Str::uuid()->toString());

        // Linnworks uses -1.0 to indicate "use default tax rate" (must be double, not int)
        $taxRate = $command->taxRate->isStandard() ? -1.0 : $command->taxRate->percentage;

        $inventoryItem = [
            'StockItemId' => $stockItemId->value,
            'ItemNumber' => $command->sku->value,
            'ItemTitle' => $command->title,
            'CategoryId' => $categoryId->value,
            'RetailPrice' => $command->retailPrice->toGross(),
            'PurchasePrice' => $command->purchasePrice?->toNet() ?? 0.0,
            'TaxRate' => $taxRate,
            'BarcodeNumber' => $command->barcode !== null ? $command->barcode->value : '',
        ];

        $this->transport->postFormParams(
            endpoint: '/api/Inventory/AddInventoryItem',
            params: ['inventoryItem' => $inventoryItem],
        );

        return $stockItemId;
    }

    /**
     * {@inheritDoc}
     *
     * @throws ResourceNotFoundException When stock item or supplier not found
     * @throws InvalidApiRequestException When parameters invalid
     * @throws InvalidApiResponseException When API response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function createSupplierStat(Sku|Guid $identifier, SupplierLinkParams $params): void
    {
        $stockItemId = $this->inventoryClient->resolveStockItemId($identifier);

        $supplierStat = [
            'StockItemId' => $stockItemId->value,
            'SupplierID' => $params->supplierId->value,
            'PurchasePrice' => $params->purchasePrice?->toNet() ?? 0.0,
            'Code' => $params->supplierCode ?? '',
            'IsDefault' => $params->isDefault,
        ];

        $this->transport->postFormParams(
            endpoint: '/api/Inventory/CreateStockSupplierStat',
            params: ['itemSuppliers' => [$supplierStat]],
        );
    }

    /**
     * {@inheritDoc}
     *
     * @throws ResourceNotFoundException When stock item not found
     * @throws InvalidApiRequestException When parameters invalid or $name not a known EP
     * @throws InvalidApiResponseException When API response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function addExtendedProperty(Sku|Guid $identifier, string $name, string $value): void
    {
        $stockItemId = $this->inventoryClient->resolveStockItemId($identifier);

        $this->createExtendedProperty(
            ExtendedPropertyWrite::fromString($name, $value),
            $stockItemId,
        );
    }

    /**
     * {@inheritDoc}
     *
     * @throws ResourceNotFoundException When stock item not found
     * @throws InvalidApiRequestException When parameters invalid or URL unreachable
     * @throws InvalidApiResponseException When API response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function addImage(Sku|Guid $identifier, string $imageUrl): void
    {
        // AddImageToInventoryItem uses SKU (ItemNumber), not stockItemId
        // Use SKU directly if available, otherwise fetch item to get SKU
        $sku = $identifier instanceof Sku
            ? $identifier->value
            : $this->inventoryClient->getStockItemFull($identifier)->sku;

        $this->transport->post(
            endpoint: '/api/Inventory/AddImageToInventoryItem',
            data: [
                'ItemNumber' => $sku,
                'IsMain' => true,
                'ImageUrl' => $imageUrl,
            ],
        );
    }

    /**
     * {@inheritDoc}
     *
     * @throws ResourceNotFoundException When stock item not found
     * @throws InvalidApiRequestException When parameters invalid
     * @throws InvalidApiResponseException When API response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function deleteInventoryItem(Sku|Guid $identifier): void
    {
        $stockItemId = $this->inventoryClient->resolveStockItemId($identifier);

        $this->deleteInventoryItems([$stockItemId]);
    }

    /**
     * {@inheritDoc}
     *
     * @throws ResourceNotFoundException When stock item not found
     * @throws InvalidApiRequestException When parameters invalid
     * @throws InvalidApiResponseException When API response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function setExtendedProperties(Sku|Guid $identifier, array $properties): void
    {
        if ($properties === []) {
            return;
        }

        $stockItem = $this->inventoryClient->getStockItemFull($identifier);
        $stockItemId = new Guid($stockItem->stockItemId);

        /** @var list<array<string, string>> $updates */
        $updates = [];
        /** @var list<array<string, string>> $creates */
        $creates = [];

        foreach ($properties as $property) {
            $existing = $stockItem->getExtendedProperty($property->name->value);

            if ($existing !== null && $existing->value === $property->value) {
                continue; // Already matches — skip write
            }

            if ($existing !== null) {
                $updates[] = self::buildExtendedPropertyPayload($property, $stockItemId, $existing->rowId);
            } else {
                $creates[] = self::buildExtendedPropertyPayload($property, $stockItemId);
            }
        }

        if ($updates !== []) {
            $this->transport->postFormParams(
                endpoint: '/api/Inventory/UpdateInventoryItemExtendedProperties',
                params: ['inventoryItemExtendedProperties' => $updates],
            );
        }

        if ($creates !== []) {
            $this->transport->postFormParams(
                endpoint: '/api/Inventory/CreateInventoryItemExtendedProperties',
                params: ['inventoryItemExtendedProperties' => $creates],
            );
        }
    }

    /**
     * {@inheritDoc}
     *
     * @throws ResourceNotFoundException When resource not found
     * @throws InvalidApiRequestException When parameters invalid
     * @throws InvalidApiResponseException When API response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function updateBulkSupplierPurchasePrice(Guid $supplierGuid, array $stockItemPrices): void
    {
        $payload = UpdateStockSupplierStatRequest::buildBulkPayload($supplierGuid, $stockItemPrices);
        $this->transport->postFormParams(
            endpoint: '/api/Inventory/UpdateStockSupplierStat',
            params: ['itemSuppliers' => $payload],
        );
    }

    /**
     * Create a new extended property on a stock item.
     *
     * @throws ResourceNotFoundException When stock item not found
     * @throws InvalidApiRequestException When parameters invalid
     * @throws InvalidApiResponseException When API response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    private function createExtendedProperty(ExtendedPropertyWrite $property, Guid $stockItemId): void
    {
        $payload = self::buildExtendedPropertyPayload($property, $stockItemId);

        $this->transport->postFormParams(
            endpoint: '/api/Inventory/CreateInventoryItemExtendedProperties',
            params: ['inventoryItemExtendedProperties' => [$payload]],
        );
    }

    /**
     * Build an extended property payload array for the Linnworks API.
     *
     * @return array<string, string>
     */
    private static function buildExtendedPropertyPayload(
        ExtendedPropertyWrite $property,
        Guid $stockItemId,
        ?string $rowId = null,
    ): array {
        // Note: Linnworks API uses "ProperyName" (typo is intentional — API expects this)
        $payload = [
            'fkStockItemId' => $stockItemId->value,
            'ProperyName' => $property->name->value,
            'PropertyValue' => $property->value,
            'PropertyType' => 'Attribute',
        ];

        if ($rowId !== null) {
            $payload['pkRowId'] = $rowId;
        }

        return $payload;
    }

    /**
     * Delete multiple inventory items.
     *
     * @param list<Guid> $stockItemIds
     *
     * @throws ResourceNotFoundException When any stock item not found
     * @throws InvalidApiRequestException When parameters invalid
     * @throws InvalidApiResponseException When API response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    private function deleteInventoryItems(array $stockItemIds): void
    {
        $ids = \array_map(
            static fn(Guid $id): string => $id->value,
            $stockItemIds,
        );

        $this->transport->postFormParams(
            endpoint: '/api/Inventory/DeleteInventoryItems',
            params: ['inventoryItemIds' => $ids],
        );
    }
}
