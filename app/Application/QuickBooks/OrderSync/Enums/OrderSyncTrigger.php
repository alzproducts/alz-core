<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Enums;

enum OrderSyncTrigger: string
{
    case Range = 'range';
    case Manual = 'manual';
    case Schedule = 'schedule';
}
