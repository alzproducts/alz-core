<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Enums;

enum FailReason: string
{
    case ZeroLines = 'zero_lines';
    case TaxRateUnsupported = 'tax_rate_unsupported';
}
