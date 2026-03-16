<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\Catalog\Filters\ValueObjects\FilterGroupDefinition;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;

/**
 * ShopWired Filter Groups API client.
 *
 * Fetches filter group definitions from ShopWired. Filter groups define
 * the faceted navigation categories (e.g., "Size", "Colour", "VAT Relief Eligible").
 *
 * Note: This endpoint is undocumented in the official ShopWired API docs
 * but is fully functional via GET /filter-groups.
 */
interface FilterGroupClientInterface
{
    /**
     * List all filter group definitions.
     *
     * Fetches all pages automatically. This is a small dataset (~10-20 groups)
     * requiring typically 1 API call.
     *
     * @return list<FilterGroupDefinition>
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotAvailableException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails
     */
    public function listAll(): array;
}
