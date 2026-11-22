<?php

declare(strict_types=1);

namespace App\Domain\QuickBooks\OrderSync\ValueObjects;

use App\Domain\QuickBooks\OrderSync\Enums\SupportedVatRate;
use App\Domain\Shared\Money\ValueObjects\DecimalMoney;
use Webmozart\Assert\Assert;

/**
 * A tax-excluded line price under COR-295 D16: a 7dp net unit price and its 2dp amount.
 */
final readonly class NetLinePrice
{
    public const int AMOUNT_SCALE = 2;

    private const int UNIT_PRICE_SCALE = 7;

    private function __construct(
        public DecimalMoney $unitPrice,
        public DecimalMoney $amount,
    ) {}

    /**
     * Divides the gross unit price by the rate's multiplier, then rounds the unit price and the amount.
     */
    public static function fromGross(
        DecimalMoney $unitPrice,
        DecimalMoney $unitPriceVat,
        SupportedVatRate $rate,
        int $quantity,
    ): self {
        Assert::greaterThan($quantity, 0, 'Line quantity must be positive');

        $netUnitPrice = $unitPrice
            ->plus($unitPriceVat)
            ->dividedBy($rate->multiplier()->toString())
            ->round(self::UNIT_PRICE_SCALE);

        return new self($netUnitPrice, $netUnitPrice->times($quantity)->round(self::AMOUNT_SCALE));
    }
}
