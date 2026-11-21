<?php

declare(strict_types=1);

namespace Database\Seeders\OrderSlice;

/**
 * One row of COR-299's edge-case table; every case traces to a Zap parity-table row or a sync reason code.
 */
enum EdgeCase: string
{
    case PaymentMethod = 'payment_method';
    case CreditPaymentMethod = 'credit_payment_method';
    case OfflinePaymentMethod = 'offline_payment_method';
    case Cancelled = 'cancelled';
    case Edited = 'edited';
    case InvoiceWithCompany = 'invoice_with_company';
    case SalesReceiptWithoutCompany = 'sales_receipt_without_company';
    case NameCleaning = 'name_cleaning';
    case NameApostrophe = 'name_apostrophe';
    case DisplayNameCaseVariant = 'display_name_case_variant';
    case CountryIreland = 'country_ireland';
    case CountryIrelandVariant = 'country_ireland_variant';
    case ZeroRated = 'zero_rated';
    case VatRelief = 'vat_relief';
    case MultiQuantitySevenDp = 'multi_quantity_seven_dp';
    case DiscountedShipping = 'discounted_shipping';
    case ShippingThirdDecimalFive = 'shipping_third_decimal_five';
    case SkuOverride = 'sku_override';
    case MissingSku = 'missing_sku';
    case ZeroLines = 'zero_lines';
    case Refunded = 'refunded';
    case PartRefunded = 'part_refunded';
}
