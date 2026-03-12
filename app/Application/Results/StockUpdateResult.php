<?php

declare(strict_types=1);

namespace App\Application\Results;

use App\Domain\Exceptions\Api\AbstractApiException;
use App\Domain\Inventory\ValueObjects\ItemStockLevel;

/**
 * Result of a bulk ShopWired stock update operation.
 *
 * Contains items successfully pushed to ShopWired (2xx response) and
 * an optional transport failure from a failed batch. Callers should:
 * 1. Update local DB for $pushed items
 * 2. Re-throw $transportFailure so the job retries failed batches
 */
final readonly class StockUpdateResult
{
    /**
     * @param list<ItemStockLevel> $pushed Items pushed to ShopWired (2xx, no transport exception)
     * @param ?AbstractApiException $transportFailure First batch transport failure, if any
     */
    public function __construct(
        public array $pushed,
        public ?AbstractApiException $transportFailure = null,
    ) {}

    public static function empty(): self
    {
        return new self(pushed: []);
    }
}
