<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Application\Results\StockUpdateResult;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Inventory\ValueObjects\ItemStockLevel;

/**
 * ShopWired Stock API client.
 *
 * Handles bulk stock quantity updates via ShopWired's /stock endpoint.
 * Implementation handles HTTP communication, batching, and response validation.
 *
 * Key behaviors:
 * - Auto-batches requests (max 15 items per API call) and sends concurrently
 * - Returns partial results on batch transport failures (caller must check $transportFailures)
 * - Caller should update local DB for pushed items, then re-throw first failure for job retry
 */
interface StockClientInterface
{
    /**
     * Update stock quantities for multiple items.
     *
     * Sends items in concurrent batches of 15. On partial batch transport
     * failures, successful batches are still included in the result. The
     * caller must check $transportFailures and re-throw after updating
     * the local DB for pushed items.
     *
     * @param list<ItemStockLevel> $items Items to update (empty = StockUpdateResult::empty())
     *
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     * @throws ExternalServiceUnavailableException When HTTP pool initialization fails
     */
    public function updateStockQuantity(array $items): StockUpdateResult;
}
