<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;

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
     * @param array<string, string|int|bool|null> $customFields Field name => value pairs
     *                                                          (null removes the field)
     *
     * @throws ResourceNotFoundException When product not found (404)
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function updateCustomFields(int $productId, array $customFields): void;
}
