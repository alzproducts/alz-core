<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Clients;

use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Domain\Exceptions\ResourceNotFoundException;
use App\Domain\Inventory\ValueObjects\StockItem;
use App\Infrastructure\Linnworks\LinnworksHttpTransport;
use App\Infrastructure\Linnworks\Responses\SkuStockIdMapping;
use App\Infrastructure\Linnworks\Responses\StockItem as StockItemResponse;

/**
 * Linnworks inventory API client.
 *
 * @template-pattern Infrastructure API Client
 */
final readonly class InventoryClient implements InventoryClientInterface
{
    private const string SERVICE_NAME = 'Linnworks';

    public function __construct(
        private LinnworksHttpTransport $transport,
    ) {}

    public function getStockItemBySku(string $sku): StockItem
    {
        $stockItemId = $this->resolveStockItemId($sku);

        return $this->fetchStockItemById($stockItemId);
    }

    /**
     * Get StockItemId mappings for multiple SKUs.
     *
     * @param list<string> $skus List of SKUs to look up
     *
     * @return list<SkuStockIdMapping> Mappings found (may be fewer than requested)
     */
    public function getStockItemIdsBySkus(array $skus): array
    {
        $response = $this->transport->post(
            endpoint: '/api/Inventory/GetStockItemIdsBySKU',
            data: ['skus' => $skus],
        );

        /** @var array{Items: list<array{StockItemId: string, SKU: string}>} $data */
        $data = $response->json();

        return \array_map(
            static fn(array $item): SkuStockIdMapping => SkuStockIdMapping::from($item),
            $data['Items'],
        );
    }

    /**
     * Resolve a single SKU to its StockItemId.
     *
     * @throws ResourceNotFoundException When SKU doesn't exist in Linnworks
     */
    private function resolveStockItemId(string $sku): string
    {
        $mappings = $this->getStockItemIdsBySkus([$sku]);

        $match = \array_find(
            $mappings,
            static fn(SkuStockIdMapping $mapping): bool => $mapping->sku === $sku,
        );

        if ($match === null) {
            throw new ResourceNotFoundException(self::SERVICE_NAME, 'StockItem', $sku);
        }

        return $match->stockItemId;
    }

    /**
     * Fetch full stock item details by StockItemId.
     *
     * @throws ResourceNotFoundException When item doesn't exist
     */
    private function fetchStockItemById(string $stockItemId): StockItem
    {
        $response = $this->transport->get(
            endpoint: '/api/Inventory/GetInventoryItemById',
            query: ['id' => $stockItemId],
        );

        $data = $response->json();

        if ($data === null) {
            throw new ResourceNotFoundException(self::SERVICE_NAME, 'StockItem', $stockItemId);
        }

        return StockItemResponse::from($data)->toDomain();
    }
}
