<?php

declare(strict_types=1);

namespace App\Domain\QuickBooks\OrderSync\Enums;

enum SkipReason: string
{
    case Cancelled = 'cancelled';
    case PaymentMethodUnmapped = 'payment_method_unmapped';
}
