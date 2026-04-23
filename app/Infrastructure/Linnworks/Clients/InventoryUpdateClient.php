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
use App\Domain\Inventory\ValueObjects\StockItemFull;
use App\Domain\Inventory\ValueObjects\StockItemSupplierStat;
use App\Domain\Inventory\ValueObjects\SupplierLinkParams;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Linnworks\Contracts\LinnworksTransportInterface;
use App\Infrastructure\Linnworks\Requests\AddInventoryItemRequest;
use App\Infrastructure\Linnworks\Requests\CreateStockSupplierStatRequest;
use App\Infrastructure\Linnworks\Requests\ExtendedPropertyRequest;
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

        $request = AddInventoryItemRequest::fromCommand($stockItemId, $categoryId, $command);

        $this->transport->postFormParams(
            endpoint: '/api/Inventory/AddInventoryItem',
            params: ['inventoryItem' => $request->toArray()],
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

        $request = CreateStockSupplierStatRequest::fromResolved($stockItemId, $params);

        $this->transport->postFormParams(
            endpoint: '/api/Inventory/CreateStockSupplierStat',
            params: ['itemSuppliers' => [$request->toArray()]],
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

        ['updates' => $updates, 'creates' => $creates] = self::partitionExtendedProperties(
            $properties,
            $stockItem,
            $stockItemId,
        );

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
     * Split desired extended properties into update vs create payloads based on
     * whether a matching row already exists on the stock item. Properties whose
     * current value equals the desired value are skipped entirely.
     *
     * @param list<ExtendedPropertyWrite> $properties
     *
     * @return array{updates: list<array<string, string>>, creates: list<array<string, string>>}
     */
    private static function partitionExtendedProperties(
        array $properties,
        StockItemFull $stockItem,
        Guid $stockItemId,
    ): array {
        $updates = [];
        $creates = [];

        foreach ($properties as $property) {
            $existing = $stockItem->getExtendedProperty($property->name->value);

            if ($existing !== null && $existing->value === $property->value) {
                continue; // Already matches — skip write
            }

            if ($existing !== null) {
                $updates[] = ExtendedPropertyRequest::fromWrite($property, $stockItemId, $existing->rowId)->toArray();
            } else {
                $creates[] = ExtendedPropertyRequest::fromWrite($property, $stockItemId)->toArray();
            }
        }

        return ['updates' => $updates, 'creates' => $creates];
    }

    /**
     * {@inheritDoc}
     *
     * @param list<StockItemSupplierStat> $supplierStats
     *
     * @throws ResourceNotFoundException When resource not found
     * @throws InvalidApiRequestException When parameters invalid
     * @throws InvalidApiResponseException When API response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function updateStockSupplierStats(array $supplierStats): void
    {
        $payload = UpdateStockSupplierStatRequest::buildBulkPayload($supplierStats);
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
        $this->transport->postFormParams(
            endpoint: '/api/Inventory/CreateInventoryItemExtendedProperties',
            params: ['inventoryItemExtendedProperties' => [ExtendedPropertyRequest::fromWrite($property, $stockItemId)->toArray()]],
        );
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
