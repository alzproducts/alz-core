<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Clients;

use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiRequestException;
use App\Domain\Exceptions\InvalidApiResponseException;
use App\Domain\Exceptions\ResourceNotFoundException;
use App\Domain\Inventory\ValueObjects\StockItem;
use App\Infrastructure\Linnworks\LinnworksHttpTransport;
use App\Infrastructure\Linnworks\Responses\SkuStockIdMappingResponse;
use App\Infrastructure\Linnworks\Responses\StockItemResponse;
use App\Infrastructure\Linnworks\Support\LinnworksResponseParserTrait;

/**
 * Linnworks inventory API client.
 *
 * @template-pattern Infrastructure API Client
 */
final readonly class InventoryClient implements InventoryClientInterface
{
    use LinnworksResponseParserTrait;

    public function __construct(
        private LinnworksHttpTransport $transport,
    ) {}

    /**
     * @throws ResourceNotFoundException When item doesn't exist
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     */
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
     * @return list<SkuStockIdMappingResponse> Mappings found (may be fewer than requested)
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found
     */
    private function getStockItemIdsBySkus(array $skus): array
    {
        $response = $this->transport->post(
            endpoint: '/api/Inventory/GetStockItemIdsBySKU',
            data: ['SKUS' => $skus],
        );

        /** @var list<SkuStockIdMappingResponse> */
        return self::parseWrappedArray($response->json(), SkuStockIdMappingResponse::class);
    }

    /**
     * Resolve a single SKU to its StockItemId.
     *
     * @throws ResourceNotFoundException When SKU doesn't exist in Linnworks
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    private function resolveStockItemId(string $sku): string
    {
        $mappings = $this->getStockItemIdsBySkus([$sku]);

        $match = \array_find(
            $mappings,
            static fn(SkuStockIdMappingResponse $mapping): bool => $mapping->sku === $sku,
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
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
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

        /** @var StockItem */
        return self::parseSingleToDomain($data, StockItemResponse::class);
    }
}
