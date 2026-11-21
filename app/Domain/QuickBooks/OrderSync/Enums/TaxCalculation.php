<?php

declare(strict_types=1);

namespace App\Domain\QuickBooks\OrderSync\Enums;

enum TaxCalculation: string
{
    case TaxExcluded = 'TaxExcluded';
}
