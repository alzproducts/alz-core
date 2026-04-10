<?php

declare(strict_types=1);

namespace App\Application\Linnworks\DTOs;

use App\Domain\Inventory\ValueObjects\StockItemFull;

/**
 * Archived/logically-deleted stock item transport shape.
 *
 * Wraps a {@see StockItemFull} with the two archive flags that live outside
 * the domain VO. Used by the SQL Dashboards-based archived sync — keeping
 * the flags here (rather than on the VO) contains the blast radius so the
 * active daily/cursor sync paths remain untouched.
 */
final readonly class ArchivedStockItemDTO
{
    public function __construct(
        public StockItemFull $item,
        public bool $isArchived,
        public bool $isLogicallyDeleted,
    ) {}
}
