<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

/**
 * Specifies how the new SKU value is determined.
 *
 * - Provided: User supplies the exact new SKU value
 * - Generated: System generates new SKU via Linnworks GetNewItemNumber
 */
enum SkuUpdateType: string
{
    case Provided = 'provided';
    case Generated = 'generated';
}
