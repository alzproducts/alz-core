<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\StockUpdateFailedException;
use App\Domain\Inventory\ValueObjects\ItemStockLevel;

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
     * @throws StockUpdateFailedException When updated count doesn't match expected
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function updateStockQuantity(array $items): void;
}
