<?php

declare(strict_types=1);

namespace App\Application\Results;

use App\Domain\Inventory\ValueObjects\ItemStockLevel;

/**
 * Result of a bulk ShopWired stock update operation.
 *
 * All items in a successful push are considered succeeded — ShopWired
 * silently accepts unknown SKUs (returning updated=0) without signalling
 * failure. Per-item failure tracking is not possible via this API.
 * Any transport exception (4xx/5xx) propagates before this result is created.
 */
final readonly class StockUpdateResult
{
    /**
     * @param list<ItemStockLevel> $succeeded Items accepted by ShopWired (2xx, no transport exception)
     */
    public function __construct(
        public array $succeeded,
    ) {}

    public static function empty(): self
    {
        return new self(succeeded: []);
    }
}
