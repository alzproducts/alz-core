<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use Generator;

/**
 * ShopWired Products API client.
 *
 * Provides methods for fetching products from the ShopWired API.
 * Products include variations, images, categories, and custom fields.
 *
 * Note: ShopWired Products API only supports title/title_desc sorting,
 * which isn't useful for incremental sync. Use full sync approach.
 */
interface ProductClientInterface
{
    /**
     * List all products with full embedded data.
     *
     * Fetches all pages automatically, loading into memory.
     * Use for small catalogs or when you need all products at once.
     * For large catalogs, prefer iterateProductBatches().
     *
     * @return list<Product>
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotAvailableException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function listAllProducts(): array;

    /**
     * Iterate products in batches (memory-efficient).
     *
     * Yields batches of ~100 products per page, allowing the caller to process
     * and discard each batch before fetching the next. Use for syncing large catalogs.
     *
     * @return Generator<int, list<Product>, mixed, void> Yields batches of products (page number as key)
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotAvailableException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function iterateProductBatches(): Generator;

    /**
     * Get a single product by its ShopWired ID.
     *
     * Includes full embedded data (variations, images, custom fields).
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotAvailableException When product not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function getProductById(int $id): Product;

    /**
     * Get the total count of products.
     *
     * Useful for progress tracking during sync operations.
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotAvailableException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function getProductCount(): int;

    /**
     * Get all product external IDs (lightweight).
     *
     * Returns only the ShopWired product IDs without full product data.
     * Use for reconciliation to identify orphaned local records.
     *
     * @return list<int> ShopWired product IDs
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotAvailableException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function getAllProductIds(): array;
}
