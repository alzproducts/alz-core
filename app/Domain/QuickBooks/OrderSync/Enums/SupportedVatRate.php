<?php

declare(strict_types=1);

namespace App\Domain\QuickBooks\OrderSync\Enums;

use App\Domain\QuickBooks\OrderSync\Exceptions\UnsupportedVatRateException;
use App\Domain\Shared\Money\ValueObjects\VatMultiplier;
use App\Domain\ValueObjects\TaxRate;

/**
 * The only VAT rates the Zap's tax-code lookup knows; any other rate has no tax code to post.
 */
enum SupportedVatRate: int
{
    case Standard = 20;
    case Zero = 0;

    /**
     * @throws UnsupportedVatRateException
     */
    public static function fromTaxRate(?TaxRate $rate, string $orderReference): self
    {
        return match (true) {
            $rate?->isStandard() === true => self::Standard,
            $rate?->isZero() === true => self::Zero,
            default => throw new UnsupportedVatRateException($orderReference, $rate?->percentage),
        };
    }

    public function multiplier(): VatMultiplier
    {
        return VatMultiplier::fromTruncatedRate((string) $this->value);
    }
}
