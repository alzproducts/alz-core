<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Application\Linnworks\UseCases\SyncStockItemBatchUseCase;
use App\Application\Shopwired\Services\ProductSyncService;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Data\MissingRequiredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Infrastructure\PartialPersistenceFailureException;
use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\IntId;
use Psr\Log\LoggerInterface;

/**
 * Force-refresh a product's data from ShopWired and Linnworks synchronously.
 */
final readonly class RefreshProductViewUseCase
{
    public function __construct(
        private ProductSyncService $productSync,
        private InventoryClientInterface $inventoryClient,
        private SyncStockItemBatchUseCase $syncStockItemBatch,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws ResourceNotAvailableException When product not found in ShopWired
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws InvalidApiRequestException When request parameters invalid
     * @throws InvalidApiResponseException When API response parsing fails
     * @throws ExternalServiceUnavailableException When ShopWired API or database unavailable
     * @throws DatabaseOperationFailedException When product sync database save fails
     * @throws DuplicateRecordException When product sync unique constraint violated
     * @throws MissingRequiredDataException When product has no SKUs or no matching Linnworks stock items
     * @throws ResourceNotFoundException When Linnworks resource not found
     * @throws PartialPersistenceFailureException When some stock items fail to persist
     */
    public function execute(IntId $productId): void
    {
        $this->logger->info('Refreshing product data', ['product_id' => $productId->value]);

        $product = $this->productSync->refreshById($productId->value);

        $this->syncLinnworksStockItems($product);

        $this->logger->info('Product refresh complete', ['product_id' => $productId->value]);
    }

    /**
     * @throws MissingRequiredDataException When product has no SKUs or no matching stock items
     * @throws ResourceNotFoundException When Linnworks resource not found
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API or database unavailable
     * @throws InvalidApiRequestException When request parameters invalid
     * @throws InvalidApiResponseException When API response parsing fails
     * @throws PartialPersistenceFailureException When some stock items fail to persist
     */
    private function syncLinnworksStockItems(Product $product): void
    {
        $allSkus = $product->allSkus();

        if ($allSkus === []) {
            throw new MissingRequiredDataException(
                dataType: 'product SKUs',
                operation: 'product refresh',
                resolution: 'Ensure product has SKUs in ShopWired',
            );
        }

        $guids = $this->resolveStockItemGuids($allSkus);

        $this->syncStockItemBatch->execute($guids);
    }

    /**
     * Resolve product SKUs to Linnworks stock item GUIDs.
     *
     * @param list<Sku> $skus
     *
     * @return list<Guid>
     *
     * @throws MissingRequiredDataException When no matching stock items exist in Linnworks
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiRequestException When request parameters invalid
     * @throws InvalidApiResponseException When API response parsing fails
     * @throws ResourceNotFoundException When Linnworks resource not found
     */
    private function resolveStockItemGuids(array $skus): array
    {
        $skuToGuid = $this->inventoryClient->resolveStockItemIds($skus);

        if ($skuToGuid === []) {
            throw new MissingRequiredDataException(
                dataType: 'Linnworks stock items',
                operation: 'product refresh',
                resolution: 'List product SKUs in Linnworks first',
            );
        }

        return \array_values($skuToGuid);
    }
}
