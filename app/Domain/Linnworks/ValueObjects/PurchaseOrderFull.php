<?php

declare(strict_types=1);

namespace App\Domain\Linnworks\ValueObjects;

/**
 * Linnworks purchase order complete composite value object — REST API data.
 *
 * Extends PurchaseOrderCore with data only available via REST endpoints:
 * additional costs, delivered records, notes, and extended properties.
 *
 * Assembled from three separate API calls: Get_PurchaseOrder +
 * Get_PurchaseOrderNote + Get_PurchaseOrderExtendedProperty.
 *
 * @template-pattern Domain Value Object
 */
final readonly class PurchaseOrderFull
{
    /**
     * @param list<PurchaseOrderAdditionalCost>   $additionalCosts
     * @param list<PurchaseOrderDeliveredRecord>   $deliveredRecords
     * @param list<PurchaseOrderNote>              $notes
     * @param list<PurchaseOrderExtendedProperty>  $extendedProperties
     */
    public function __construct(
        public PurchaseOrderCore $core,
        public array $additionalCosts,
        public array $deliveredRecords,
        public array $notes,
        public array $extendedProperties,
    ) {}
}
