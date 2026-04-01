<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Clients;

use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Data\InvalidSkuException;
use App\Domain\Inventory\ValueObjects\StockItem;
use App\Domain\Inventory\ValueObjects\StockItemFull;
use App\Domain\Inventory\ValueObjects\Supplier;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Linnworks\Contracts\LinnworksTransportInterface;
use App\Infrastructure\Linnworks\Responses\SkuStockIdMappingResponse;
use App\Infrastructure\Linnworks\Responses\StockItemFullResponse;
use App\Infrastructure\Linnworks\Responses\StockItemResponse;
use App\Infrastructure\Linnworks\Responses\SupplierResponse;
use App\Infrastructure\Linnworks\Support\LinnworksResponseParserTrait;
use Generator;

/**
 * Linnworks inventory API client.
 *
 * @template-pattern Infrastructure API Client
 */
final readonly class InventoryClient implements InventoryClientInterface
{
    use LinnworksResponseParserTrait;

    /**
     * Data requirements for full stock item responses.
     *
     * Used by both GetStockItemsFull and GetStockItemsFullByIds endpoints
     * to ensure consistent data is returned.
     *
     * @var list<string>
     */
    private const array DATA_REQUIREMENTS_FULL = [
        'ExtendedProperties',
        'StockLevels',
        'Pricing',
        'Supplier',
    ];

    /**
     * Data requirements for GetStockItemsFullByIds endpoint.
     *
     * Unlike GetStockItemsFull, this endpoint doesn't support 'Pricing' requirement.
     * Prices are still returned in the base response fields (PurchasePrice, RetailPrice).
     *
     * @var list<string>
     */
    private const array DATA_REQUIREMENTS_BY_IDS = [
        'ExtendedProperties',
        'StockLevels',
        'Supplier',
    ];

    public function __construct(
        private LinnworksTransportInterface $transport,
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
        $stockItemId = $this->resolveSkuToStockItemId($sku);

        return $this->fetchStockItemById($stockItemId->value);
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
     * {@inheritDoc}
     *
     * @return array<string, Guid>
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found (404)
     */
    public function resolveStockItemIds(array $skus): array
    {
        $skuStrings = \array_map(
            static fn(Sku $sku): string => $sku->value,
            $skus,
        );

        $mappings = $this->getStockItemIdsBySkus($skuStrings);

        $result = [];

        foreach ($mappings as $mapping) {
            $result[$mapping->sku] = new Guid($mapping->stockItemId);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     *
     * @throws ResourceNotFoundException When SKU doesn't exist in Linnworks
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function resolveStockItemId(Sku|Guid $identifier): Guid
    {
        if ($identifier instanceof Guid) {
            return $identifier;
        }

        return $this->resolveSkuToStockItemId($identifier->value);
    }

    /**
     * Resolve a single SKU string to its StockItemId GUID.
     *
     * @throws ResourceNotFoundException When SKU doesn't exist in Linnworks
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    private function resolveSkuToStockItemId(string $sku): Guid
    {
        $mappings = $this->getStockItemIdsBySkus([$sku]);

        $match = \array_find(
            $mappings,
            static fn(SkuStockIdMappingResponse $mapping): bool => $mapping->sku === $sku,
        );

        if ($match === null) {
            throw new ResourceNotFoundException(self::SERVICE_NAME, 'StockItem', $sku);
        }

        return new Guid($match->stockItemId);
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

    /**
     * Iterate all stock items with extended properties in batches.
     *
     * Memory-efficient generator that fetches stock items from GetStockItemsFull
     * endpoint with ExtendedProperties included. Yields batches of ~200 items.
     *
     * @return Generator<int, list<StockItemFull>, mixed, void> Yields batches (page number as key)
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found (404)
     */
    public function iterateStockItemBatches(): Generator
    {
        $batchSize = 200;
        $pageNumber = 1;

        do {
            $batch = $this->fetchStockItemsFullPage($pageNumber, $batchSize);
            $batchCount = \count($batch);

            if ($batchCount > 0) {
                yield $pageNumber => $batch;
            }

            $pageNumber++;
        } while ($batchCount === $batchSize);
    }

    /**
     * Fetch a single page from GetStockItemsFull endpoint.
     *
     * Note: This endpoint uses raw form params (not the standard 'request' JSON wrapper).
     * Format discovered via legacy alz-connect project: \Alz\ProductsA::Linn_GetStockItemsFull_BySearch
     *
     * @see https://apidocs.linnworks.net/reference/getstockitemsfull
     *
     * @return list<StockItemFull>
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found (404)
     */
    private function fetchStockItemsFullPage(int $pageNumber, int $entriesPerPage): array
    {
        $response = $this->transport->postFormParams(
            endpoint: '/api/Stock/GetStockItemsFull',
            params: [
                'dataRequirements' => self::DATA_REQUIREMENTS_FULL,
                'loadCompositeParents' => true,
                'loadVariationParents' => false,
                'entriesPerPage' => $entriesPerPage,
                'pageNumber' => $pageNumber,
            ],
        );

        /** @var list<StockItemFull> */
        return self::parseDirectArrayToDomain($response->json(), StockItemFullResponse::class);
    }

    /**
     * {@inheritDoc}
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When response format unexpected
     * @throws ResourceNotFoundException When resource not found
     * @throws InvalidSkuException When generated SKU fails validation (should not happen)
     */
    public function getNewItemNumber(): Sku
    {
        $response = $this->transport->get(
            endpoint: '/api/Inventory/GetNewItemNumber',
        );

        $value = $response->json();

        if (!\is_string($value) || $value === '') {
            throw new InvalidApiResponseException(
                self::SERVICE_NAME,
                'GetNewItemNumber returned invalid response',
            );
        }

        return Sku::fromString($value);
    }

    /**
     * {@inheritDoc}
     *
     * @throws ResourceNotFoundException When stock item doesn't exist
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function getStockItemFull(Sku|Guid $identifier): StockItemFull
    {
        $stockItemId = $this->resolveStockItemId($identifier);
        $items = $this->fetchStockItemsFullByIds([$stockItemId->value]);

        if ($items === []) {
            throw new ResourceNotFoundException(
                self::SERVICE_NAME,
                'StockItem',
                $stockItemId->value,
            );
        }

        return $items[0];
    }

    /**
     * {@inheritDoc}
     *
     * @return list<Supplier>
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found (404)
     */
    public function getSuppliers(): array
    {
        $response = $this->transport->post(
            endpoint: '/api/Inventory/GetSuppliers',
            data: [],
        );

        /** @var list<Supplier> */
        return self::parseDirectArrayToDomain($response->json(), SupplierResponse::class);
    }

    /**
     * Fetch full stock items by their StockItemIds.
     *
     * Uses GetStockItemsFullByIds endpoint which returns the same structure
     * as GetStockItemsFull but for specific IDs.
     *
     * @param list<string> $stockItemIds GUIDs of stock items to fetch
     *
     * @return list<StockItemFull>
     *
     * @throws ResourceNotFoundException When resource not found
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    private function fetchStockItemsFullByIds(array $stockItemIds): array
    {
        if ($stockItemIds === []) {
            return [];
        }

        $response = $this->transport->post(
            endpoint: '/api/Stock/GetStockItemsFullByIds',
            data: [
                'StockItemIds' => $stockItemIds,
                'DataRequirements' => self::DATA_REQUIREMENTS_BY_IDS,
            ],
        );

        /** @var list<StockItemFull> */
        return self::parseWrappedArrayToDomain(
            $response->json(),
            StockItemFullResponse::class,
            'StockItemsFullExtended',
        );
    }
}
