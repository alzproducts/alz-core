<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\Catalog\Category\ValueObjects\CategoryFieldUpdate;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;

/**
 * Client for updating ShopWired categories.
 *
 * Scalar fields use simple PUT. Custom fields use fetch-merge-PUT
 * to preserve existing values not included in the update.
 */
interface CategoryUpdateClientInterface
{
    /**
     * Update scalar fields on a category via simple PUT.
     *
     * @throws ResourceNotAvailableException When category not found (404)
     * @throws InvalidApiRequestException When request parameters invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid (401/403)
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function update(int $categoryId, CategoryFieldUpdate ...$updates): void;

    /**
     * Update custom fields on a category.
     *
     * Fetches current category, merges new values with existing custom fields,
     * and PUTs the merged result. This preserves any custom fields not
     * explicitly included in the update.
     *
     * @param int $categoryId ShopWired category ID
     * @param array<string, string|int|bool|list<string>|list<int>|null> $customFields Field name => value pairs
     *                                                          (null removes the field)
     *
     * @throws ResourceNotAvailableException When category not found (404)
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     */
    public function updateCustomFields(int $categoryId, array $customFields): void;
}
