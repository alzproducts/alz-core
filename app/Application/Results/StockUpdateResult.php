<?php

declare(strict_types=1);

namespace App\Application\Results;

use App\Domain\Exceptions\Api\AbstractApiException;
use App\Domain\Inventory\ValueObjects\ItemStockLevel;

/**
 * Result of a bulk ShopWired stock update operation.
 *
 * Contains items successfully pushed to ShopWired (2xx response) and
 * any transport failures from failed batches. Callers should:
 * 1. Update local DB for $pushed items
 * 2. Re-throw first failure so the job retries failed batches
 */
final readonly class StockUpdateResult
{
    /**
     * @param list<ItemStockLevel> $pushed Items pushed to ShopWired (2xx, no transport exception)
     * @param list<AbstractApiException> $transportFailures All batch transport failures (empty = all succeeded)
     */
    public function __construct(
        public array $pushed,
        public array $transportFailures = [],
    ) {}

    public static function empty(): self
    {
        return new self(pushed: []);
    }
}
