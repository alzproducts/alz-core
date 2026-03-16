<?php

declare(strict_types=1);

namespace App\Application\Shopwired\Services;

use App\Application\Contracts\Shopwired\ProductClientInterface;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

/**
 * Single-product sync service for refreshing local data from ShopWired API.
 *
 * Use this when you need to refresh a specific product after external mutations
 * (e.g., after updating variations via ShopWired API, refresh local cache).
 *
 * For bulk sync operations, use SyncProductsUseCase instead.
 */
final readonly class ProductSyncService
{
    public function __construct(
        private ProductClientInterface $productClient,
        private ProductRepositoryInterface $productRepository,
    ) {}

    /**
     * Fetch a product from ShopWired API and persist to local database.
     *
     * @param int $productId ShopWired external product ID
     *
     * @return Product The refreshed product
     *
     * @throws ResourceNotAvailableException When product not found in ShopWired
     * @throws AuthenticationExpiredException When ShopWired credentials invalid
     * @throws InvalidApiRequestException When request parameters invalid
     * @throws InvalidApiResponseException When API response parsing fails
     * @throws ExternalServiceUnavailableException When ShopWired API or database unavailable
     * @throws DatabaseOperationFailedException When database save fails
     * @throws DuplicateRecordException When unique constraint violated
     */
    public function refreshById(int $productId): Product
    {
        $product = $this->productClient->getProductById($productId);

        $this->productRepository->save($product);

        return $product;
    }
}
