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
use App\Domain\Inventory\Enums\LinnworksInventoryField;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Linnworks\LinnworksHttpTransport;

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
        $stockItemId = $this->resolveStockItemId($identifier);

        $this->transport->postFormParams(
            endpoint: '/api/Inventory/UpdateInventoryItemField',
            params: [
                'inventoryItemId' => $stockItemId,
                'fieldName' => LinnworksInventoryField::SKU->value,
                'fieldValue' => $newSku->value,
            ],
        );
    }

    /**
     * Resolve identifier to Linnworks stockItemId.
     *
     * @throws ResourceNotFoundException When SKU not found in Linnworks
     * @throws InvalidApiRequestException When request parameters invalid
     * @throws InvalidApiResponseException When API response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    private function resolveStockItemId(Sku|Guid $identifier): string
    {
        if ($identifier instanceof Guid) {
            return $identifier->value;
        }

        // SKU requires resolution via API
        return $this->inventoryClient->getStockItemBySku($identifier->value)->stockItemId;
    }
}
