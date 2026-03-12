<?php

declare(strict_types=1);

namespace App\Application\Results;

use App\Domain\Inventory\ValueObjects\ItemStockLevel;

/**
 * Result of a bulk ShopWired stock update operation.
 *
 * Tracks which items were confirmed updated by the API and which were
 * not, enabling callers to update the local DB snapshot precisely.
 * Items in $failed will be retried on the next sync cycle.
 */
final readonly class StockUpdateResult
{
    /**
     * @param list<ItemStockLevel> $succeeded Items confirmed updated by ShopWired
     * @param list<ItemStockLevel> $failed Items not updated (SKU unknown to ShopWired, or batch error after individual retry)
     */
    public function __construct(
        public array $succeeded,
        public array $failed,
    ) {}

    public function hasFailures(): bool
    {
        return $this->failed !== [];
    }

    public static function empty(): self
    {
        return new self(succeeded: [], failed: []);
    }
}
