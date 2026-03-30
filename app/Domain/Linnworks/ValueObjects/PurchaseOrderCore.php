<?php

declare(strict_types=1);

namespace App\Domain\Linnworks\ValueObjects;

/**
 * Linnworks purchase order composite value object — single API call.
 *
 * Assembles all data returned by a single Get_PurchaseOrder call:
 * the header, item lines, additional costs, delivery records, and note count.
 *
 * Use this for rapid polling of OPEN/PENDING purchase orders where
 * notes and extended properties are not needed.
 *
 * @template-pattern Domain Value Object
 */
final readonly class PurchaseOrderCore
{
    /**
     * @param list<PurchaseOrderItem>             $items
     * @param list<PurchaseOrderAdditionalCost>   $additionalCosts
     * @param list<PurchaseOrderDeliveredRecord>  $deliveredRecords
     */
    public function __construct(
        public PurchaseOrderHeader $header,
        public int $noteCount,
        public array $items,
        public array $additionalCosts,
        public array $deliveredRecords,
    ) {}
}
