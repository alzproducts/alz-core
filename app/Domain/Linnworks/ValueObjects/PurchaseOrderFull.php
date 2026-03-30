<?php

declare(strict_types=1);

namespace App\Domain\Linnworks\ValueObjects;

/**
 * Linnworks purchase order complete composite value object — three API calls.
 *
 * Extends PurchaseOrderCore with notes and extended properties, assembled
 * from three separate API calls: Get_PurchaseOrder + Get_PurchaseOrderNote
 * + Get_PurchaseOrderExtendedProperty.
 *
 * Use this for historical backfill / complete sync where full metadata
 * including notes and extended properties is required.
 *
 * @template-pattern Domain Value Object
 */
final readonly class PurchaseOrderFull
{
    /**
     * @param list<PurchaseOrderNote>             $notes
     * @param list<PurchaseOrderExtendedProperty> $extendedProperties
     */
    public function __construct(
        public PurchaseOrderCore $core,
        public array $notes,
        public array $extendedProperties,
    ) {}
}
