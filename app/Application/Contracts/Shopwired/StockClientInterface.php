<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Application\Results\StockUpdateResult;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Inventory\ValueObjects\ItemStockLevel;
use RuntimeException;

/**
 * ShopWired Stock API client.
 *
 * Handles bulk stock quantity updates via ShopWired's /stock endpoint.
 * Implementation handles HTTP communication, batching, and response validation.
 *
 * Key behaviors:
 * - Auto-batches requests (max 15 items per API call) and sends concurrently
 * - On count mismatch, fans out to individual requests to isolate failing SKUs
 * - Returns StockUpdateResult so callers update only confirmed-succeeded items
 */
interface StockClientInterface
{
    /**
     * Update stock quantities for multiple items.
     *
     * Sends items in concurrent batches of 15. If a batch returns an updated
     * count that doesn't match the batch size, each item is retried individually
     * to isolate failures. The result distinguishes confirmed successes from
     * failures so the caller can update the local DB snapshot precisely.
     *
     * Transport errors (429, 5xx, connection failures) are not caught here —
     * they propagate as domain exceptions for job-level retry handling.
     *
     * @param list<ItemStockLevel> $items Items to update (empty = StockUpdateResult::empty())
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     * @throws RuntimeException When HTTP pool initialization fails (Laravel/Guzzle internals)
     */
    public function updateStockQuantity(array $items): StockUpdateResult;
}
