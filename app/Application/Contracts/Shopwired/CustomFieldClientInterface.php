<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;

/**
 * ShopWired Custom Fields API client.
 *
 * Fetches custom field definitions (schema/metadata) from ShopWired.
 * These definitions describe the available custom fields for products,
 * categories, customers, orders, etc.
 *
 * This is a simple endpoint with ~100-150 records total, so no generator
 * or batching is needed - listAll() loads everything in 2-3 API calls.
 *
 * @see https://shopwired.readme.io/reference/listcustomfields
 */
interface CustomFieldClientInterface
{
    /**
     * List all custom field definitions.
     *
     * Fetches all pages automatically (typically 2-3 API calls for ~100-150 definitions).
     * Results include all item types (product, category, customer, brand, order, page, blog_post).
     *
     * @return list<CustomFieldDefinition>
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotAvailableException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails or no definitions returned
     */
    public function listAll(): array;
}
