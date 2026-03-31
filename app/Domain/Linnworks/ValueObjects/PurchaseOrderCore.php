<?php

declare(strict_types=1);

namespace App\Domain\Linnworks\ValueObjects;

/**
 * Linnworks purchase order composite value object — SQL-fetchable data only.
 *
 * Contains header, note count, and item lines — all available via the
 * Linnworks Dashboards SQL API in batch queries.
 *
 * Additional costs, delivered records, notes, and extended properties
 * live on PurchaseOrderFull (REST-only data).
 *
 * @template-pattern Domain Value Object
 */
final readonly class PurchaseOrderCore
{
    /**
     * @param list<PurchaseOrderItem> $items
     */
    public function __construct(
        public PurchaseOrderHeader $header,
        public int $noteCount,
        public array $items,
    ) {}
}
