<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\Catalog\ValueObjects\Brand;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;

/**
 * ShopWired Brands API client.
 *
 * Handles brand retrieval operations from ShopWired API.
 * Implementation handles HTTP communication, authentication, and response parsing.
 */
interface BrandClientInterface
{
    /**
     * List ALL brands with embedded custom fields (paginated fetch).
     *
     * Fetches all pages automatically. Use for complete brand data caching.
     *
     * @return list<Brand>
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotAvailableException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function listAllBrands(): array;

    /**
     * Get a single brand by ID.
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotAvailableException When brand not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function getBrandById(int $id): Brand;

    /**
     * Get the total count of brands.
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotAvailableException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function getBrandCount(): int;
}
