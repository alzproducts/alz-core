<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Enums;

/**
 * What a stored order lacks to be a builder input.
 */
enum SourceOrderDefect: string
{
    case PaymentMethodMissing = 'payment_method_missing';
    case SkuMissing = 'sku_missing';
    case VatRateInvalid = 'vat_rate_invalid';
    case QuantityInvalid = 'quantity_invalid';
}
