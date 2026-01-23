<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\StockUpdateFailedException;
use App\Domain\Inventory\ValueObjects\ItemStockLevel;
use RuntimeException;

/**
 * ShopWired Stock API client.
 *
 * Handles bulk stock quantity updates via ShopWired's /stock endpoint.
 * Implementation handles HTTP communication, batching, and response validation.
 *
 * Key behaviors:
 * - Auto-batches requests (max 15 items per API call)
 * - Validates response count matches input count
 * - Uses concurrent requests for large batches
 */
interface StockClientInterface
{
    /**
     * Update stock quantities for multiple items.
     *
     * Batches items into groups of 15 (API limit) and sends
     * concurrent requests. Validates that the total number of
     * updated items matches the input count.
     *
     * @param list<ItemStockLevel> $items Items to update (empty = no-op)
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     * @throws StockUpdateFailedException When updated count doesn't match expected
     * @throws RuntimeException When HTTP pool initialization fails (Laravel/Guzzle internals)
     */
    public function updateStockQuantity(array $items): void;
}
