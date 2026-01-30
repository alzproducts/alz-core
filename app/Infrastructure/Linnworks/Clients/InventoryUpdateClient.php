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
use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\Money;
use App\Infrastructure\Linnworks\LinnworksHttpTransport;
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
        private LinnworksHttpTransport $transport,
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

        // Linnworks uses -1 to indicate "use default tax rate"
        $taxRate = $command->taxRate->isStandard() ? -1 : $command->taxRate->percentage;

        $inventoryItem = [
            'StockItemId' => $stockItemId->value,
            'ItemNumber' => $command->sku->value,
            'ItemTitle' => $command->title,
            'CategoryId' => $categoryId->value,
            'RetailPrice' => $command->retailPrice->toGross(),
            'PurchasePrice' => $command->purchasePrice?->toNet() ?? 0.0,
            'TaxRate' => $taxRate,
            'Barcode' => $command->barcode !== null ? $command->barcode->value : '',
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
    public function createSupplierStat(
        Sku|Guid $identifier,
        Guid $supplierId,
        ?Money $purchasePrice,
        ?string $supplierCode = null,
        bool $isDefault = false,
    ): void {
        $stockItemId = $this->inventoryClient->resolveStockItemId($identifier);

        $supplierStat = [
            'fkStockItemId' => $stockItemId->value,
            'fkSupplierId' => $supplierId->value,
            'PurchasePrice' => $purchasePrice?->toNet() ?? 0.0,
            'Code' => $supplierCode ?? '',
            'IsDefault' => $isDefault,
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
     * @throws InvalidApiRequestException When parameters invalid
     * @throws InvalidApiResponseException When API response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function addExtendedProperty(Sku|Guid $identifier, string $name, string $value): void
    {
        $stockItemId = $this->inventoryClient->resolveStockItemId($identifier);

        // Note: Linnworks API uses "ProperyName" (typo is intentional - API expects this)
        $extendedProperty = [
            'fkStockItemId' => $stockItemId->value,
            'ProperyName' => $name,
            'PropertyValue' => $value,
            'PropertyType' => 'Attribute',
        ];

        $this->transport->postFormParams(
            endpoint: '/api/Inventory/CreateInventoryItemExtendedProperties',
            params: ['inventoryItemExtendedProperties' => [$extendedProperty]],
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
                'request' => [
                    'ItemNumber' => $sku,
                    'IsMain' => true,
                    'ImageUrl' => $imageUrl,
                ],
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
