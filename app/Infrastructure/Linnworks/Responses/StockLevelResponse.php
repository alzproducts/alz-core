<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Responses;

use App\Infrastructure\Linnworks\Support\PascalCaseMapper;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Linnworks stock level response DTO.
 *
 * Represents per-location stock levels from GetStockItemsFull endpoint.
 * Each item can have multiple stock levels (one per warehouse location).
 */
#[MapInputName(PascalCaseMapper::class)]
final class StockLevelResponse extends Data
{
    public function __construct(
        public readonly StockLevelLocationResponse $location,
        public readonly int $stockLevel,
        public readonly int $minimumLevel,
        public readonly int $inOrders,
        public readonly int $due,
        public readonly int $available,
        public readonly float $unitCost,
        #[MapInputName('JIT')]
        public readonly bool $jit,
    ) {}

    public function isDefaultLocation(): bool
    {
        return $this->location->isDefaultLocation();
    }
}
