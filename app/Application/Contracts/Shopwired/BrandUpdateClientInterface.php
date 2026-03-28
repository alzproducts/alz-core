<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\Catalog\Brand\ValueObjects\BrandFieldUpdate;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;

/**
 * Client for updating ShopWired brands.
 *
 * Scalar fields use simple PUT. Custom fields use fetch-merge-PUT
 * to preserve existing values not included in the update.
 */
interface BrandUpdateClientInterface
{
    /**
     * Update scalar fields on a brand via simple PUT.
     *
     * @throws ResourceNotAvailableException When brand not found (404)
     * @throws InvalidApiRequestException When request parameters invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid (401/403)
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function update(int $brandId, BrandFieldUpdate ...$updates): void;

    /**
     * Update custom fields on a brand.
     *
     * Fetches current brand, merges new values with existing custom fields,
     * and PUTs the merged result. This preserves any custom fields not
     * explicitly included in the update.
     *
     * @param int $brandId ShopWired brand ID
     * @param array<string, string|int|bool|list<string>|list<int>|null> $customFields Field name => value pairs
     *                                                          (null removes the field)
     *
     * @throws ResourceNotAvailableException When brand not found (404)
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function updateCustomFields(int $brandId, array $customFields): void;
}
