<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;

/**
 * Client for updating ShopWired products.
 *
 * Uses fetch-merge-PUT pattern to preserve existing values while updating
 * specific fields.
 */
interface ProductUpdateClientInterface
{
    /**
     * Update custom fields on a product.
     *
     * Fetches current product, merges new values with existing custom fields,
     * and PUTs the merged result. This preserves any custom fields not
     * explicitly included in the update.
     *
     * @param int $productId ShopWired product ID
     * @param array<string, string|int|bool|list<string>|list<int>|null> $customFields Field name => value pairs
     *                                                          (null removes the field)
     *
     * @throws ResourceNotAvailableException When product not found (404)
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function updateCustomFields(int $productId, array $customFields): void;

    /**
     * Update filters on a product.
     *
     * Fetches current product, merges new filter values with existing filters,
     * and PUTs the merged result. This preserves any filters not explicitly
     * included in the update.
     *
     * @param int $productId ShopWired product ID
     * @param array<int, list<string>|null> $filters optionNo => values pairs
     *                                               (null removes the filter)
     *
     * @throws ResourceNotAvailableException When product not found (404)
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function updateFilters(int $productId, array $filters): void;
}
