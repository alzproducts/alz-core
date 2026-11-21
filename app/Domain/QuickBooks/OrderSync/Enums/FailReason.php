<?php

declare(strict_types=1);

namespace App\Domain\QuickBooks\OrderSync\Enums;

enum FailReason: string
{
    case ZeroLines = 'zero_lines';
}
