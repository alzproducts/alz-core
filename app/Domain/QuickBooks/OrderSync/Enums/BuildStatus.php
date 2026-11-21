<?php

declare(strict_types=1);

namespace App\Domain\QuickBooks\OrderSync\Enums;

enum BuildStatus: string
{
    case Built = 'built';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
