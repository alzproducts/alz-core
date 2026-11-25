<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Enums;

enum BuildStatus: string
{
    case Built = 'built';
    case Failed = 'failed';
}
