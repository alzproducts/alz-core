<?php

declare(strict_types=1);

namespace Database\Seeders\ProductSlice\Builders;

final readonly class StockLedger
{
    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $stockItemIds Linnworks stock item IDs, SKU-matched items first
     */
    public function __construct(
        public array $rows,
        public array $stockItemIds,
        public int $matchedCount,
    ) {}
}
