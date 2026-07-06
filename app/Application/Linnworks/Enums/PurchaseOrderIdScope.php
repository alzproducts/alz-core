<?php

declare(strict_types=1);

namespace App\Application\Linnworks\Enums;

enum PurchaseOrderIdScope
{
    case FastSync;
    case DateRange;
    case All;
}
