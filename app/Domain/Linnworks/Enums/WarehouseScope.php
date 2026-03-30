<?php

declare(strict_types=1);

namespace App\Domain\Linnworks\Enums;

/**
 * Business-level warehouse location filter.
 *
 * Expresses which warehouse(s) to include in queries without
 * exposing infrastructure-level location GUIDs.
 *
 * @template-pattern Domain Enum
 */
enum WarehouseScope
{
    /** Our primary warehouse only. */
    case OurWarehouse;

    /** All warehouses except ours (supplier/3PL locations). */
    case ExcludingOurWarehouse;

    /** No location filter — includes all warehouses. */
    case AnyWarehouse;
}
