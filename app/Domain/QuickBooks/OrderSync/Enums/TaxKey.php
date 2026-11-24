<?php

declare(strict_types=1);

namespace App\Domain\QuickBooks\OrderSync\Enums;

/**
 * Semantic key a line's QBO tax code resolves from (ADR-0018). `eu_goods` is for goods lines only, `eu_shipping` for the shipping line only.
 */
enum TaxKey: string
{
    case Standard = 'standard';
    case Zero = 'zero';
    case EuGoods = 'eu_goods';
    case EuShipping = 'eu_shipping';

    // intentional: exact, case-sensitive and untrimmed match, as the Zap compares it (fix is COR-321 point 13).
    private const string IRELAND = 'Ireland';

    public static function forGoods(SupportedVatRate $rate, string $deliveryCountry): self
    {
        return match ($rate) {
            SupportedVatRate::Standard => self::Standard,
            SupportedVatRate::Zero => $deliveryCountry === self::IRELAND ? self::EuGoods : self::Zero,
        };
    }

    public static function forShipping(SupportedVatRate $rate, string $deliveryCountry): self
    {
        return match ($rate) {
            SupportedVatRate::Standard => self::Standard,
            SupportedVatRate::Zero => $deliveryCountry === self::IRELAND ? self::EuShipping : self::Zero,
        };
    }

    public function vatRate(): SupportedVatRate
    {
        return match ($this) {
            self::Standard => SupportedVatRate::Standard,
            self::Zero, self::EuGoods, self::EuShipping => SupportedVatRate::Zero,
        };
    }
}
