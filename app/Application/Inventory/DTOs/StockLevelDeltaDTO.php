<?php

declare(strict_types=1);

namespace App\Application\Inventory\DTOs;

use App\Domain\Catalog\Product\ValueObjects\Sku;
use DateTimeImmutable;

/**
 * A single stock level change from Linnworks, as returned by the delta query.
 */
final readonly class StockLevelDeltaDTO
{
    public function __construct(
        public Sku $sku,
        public int $level,
        public DateTimeImmutable $lastUpdateDate,
    ) {}
}
