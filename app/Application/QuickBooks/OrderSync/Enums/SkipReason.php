<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Enums;

enum SkipReason: string
{
    case Cancelled = 'cancelled';
}
