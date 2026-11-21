<?php

declare(strict_types=1);

namespace App\Domain\QuickBooks\OrderSync\Enums;

use App\Domain\QuickBooks\OrderSync\Exceptions\UnsupportedVatRateException;
use App\Domain\Shared\Money\ValueObjects\VatMultiplier;

/**
 * The only VAT rates the Zap's tax-code lookup knows; any other rate has no tax code to post.
 */
enum SupportedVatRate: int
{
    case Standard = 20;
    case Zero = 0;

    /**
     * Compares the decimal value, so `20.0` is Standard and `20.5` is unsupported.
     *
     * @throws UnsupportedVatRateException
     */
    public static function fromRate(?string $rate, string $orderReference): self
    {
        $wholeRate = self::wholeRate($rate);

        return ($wholeRate === null ? null : self::tryFrom($wholeRate))
            ?? throw new UnsupportedVatRateException($orderReference, $rate);
    }

    /**
     * The rate as an integer when it is a plain decimal with no non-zero fraction, else null.
     */
    private static function wholeRate(?string $rate): ?int
    {
        if ($rate === null || \preg_match('/^(\d+)(?:\.(\d+))?$/', $rate, $matches) !== 1) {
            return null;
        }

        return \mb_rtrim($matches[2] ?? '', '0') === '' ? (int) $matches[1] : null;
    }

    public function multiplier(): VatMultiplier
    {
        return VatMultiplier::fromTruncatedRate((string) $this->value);
    }
}
