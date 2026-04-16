<?php

declare(strict_types=1);

namespace App\Domain\Shared\Money\ValueObjects;

use App\Domain\ValueObjects\TaxRate;
use App\Domain\ValueObjects\TaxType;
use Webmozart\Assert\Assert;

/**
 * Tax-aware monetary value.
 *
 * Every Money instance explicitly declares its tax treatment (inclusive/exclusive/zero-rated),
 * providing self-documenting prices throughout the system. Supports conversion between
 * gross (tax-inclusive) and net (tax-exclusive) values.
 *
 * @example
 * ```php
 * // ShopWired requires costPrice as tax-inclusive
 * $cost = Money::exclusive(10.00);  // £10.00 net
 * $gross = $cost->toGross();        // £12.00 (with 20% VAT)
 *
 * // Reading a price that's already inclusive
 * $price = Money::inclusive(24.00);
 * $net = $price->toNet();           // £20.00
 * ```
 */
final readonly class Money
{
    private const int DEFAULT_PRECISION = 2;

    private function __construct(
        private float $amount,
        public TaxType $taxType,
        public string $currency = 'GBP',
    ) {
        Assert::greaterThanEq($amount, 0, 'Money amount cannot be negative');
    }

    /**
     * Create a tax-inclusive (gross) price.
     *
     * @param float $amount The gross amount including tax
     * @param string $currency Currency code (default: GBP)
     * @param int|null $precision Decimal places to round to (null = no rounding)
     */
    public static function inclusive(float $amount, string $currency = 'GBP', ?int $precision = self::DEFAULT_PRECISION): self
    {
        $rounded = $precision !== null ? \round($amount, $precision) : $amount;

        return new self($rounded, TaxType::Inclusive, $currency);
    }

    /**
     * Create a tax-inclusive (gross) price from a decimal string.
     *
     * Preserves the precision of `decimal(p,s)` columns (e.g. ShopWired stores
     * order totals as `decimal(14,6)`) by passing the caller's string through
     * without the rounding that `inclusive()` applies at 2 decimal places.
     *
     * @param string $amount Numeric decimal string (e.g. "12.345678")
     * @param string $currency Currency code (default: GBP)
     */
    public static function inclusiveFromString(string $amount, string $currency = 'GBP'): self
    {
        Assert::numeric($amount, 'Money amount string must be numeric');

        return new self((float) $amount, TaxType::Inclusive, $currency);
    }

    /**
     * Create a tax-exclusive (net) price.
     *
     * @param float $amount The net amount excluding tax
     * @param string $currency Currency code (default: GBP)
     * @param int|null $precision Decimal places to round to (null = no rounding)
     */
    public static function exclusive(float $amount, string $currency = 'GBP', ?int $precision = self::DEFAULT_PRECISION): self
    {
        $rounded = $precision !== null ? \round($amount, $precision) : $amount;

        return new self($rounded, TaxType::Exclusive, $currency);
    }

    /**
     * Create a zero-rated price (no tax applies).
     *
     * Use for items exempt from VAT (children's clothing, books, etc.).
     *
     * @param float $amount The amount (same whether "gross" or "net")
     * @param string $currency Currency code (default: GBP)
     * @param int|null $precision Decimal places to round to (null = no rounding)
     */
    public static function zeroRated(float $amount, string $currency = 'GBP', ?int $precision = self::DEFAULT_PRECISION): self
    {
        $rounded = $precision !== null ? \round($amount, $precision) : $amount;

        return new self($rounded, TaxType::ZeroRated, $currency);
    }

    /**
     * Get the tax-inclusive (gross) value.
     *
     * - If already inclusive: returns the amount unchanged
     * - If exclusive: adds tax at the given rate
     * - If zero-rated: returns the amount unchanged
     *
     * @param TaxRate|null $taxRate Tax rate to apply (default: UK standard 20%)
     * @param int|null $precision Decimal places to round to (null = no rounding)
     */
    public function toGross(?TaxRate $taxRate = null, ?int $precision = self::DEFAULT_PRECISION): float
    {
        $rate = $taxRate ?? TaxRate::standard();

        $result = match ($this->taxType) {
            TaxType::Inclusive => $this->amount,
            TaxType::Exclusive => $this->amount * (1 + $rate->toDecimal()),
            TaxType::ZeroRated => $this->amount,
        };

        return $precision !== null ? \round($result, $precision) : $result;
    }

    /**
     * Get the tax-exclusive (net) value.
     *
     * - If already exclusive: returns the amount unchanged
     * - If inclusive: removes tax at the given rate
     * - If zero-rated: returns the amount unchanged
     *
     * @param TaxRate|null $taxRate Tax rate to apply (default: UK standard 20%)
     * @param int|null $precision Decimal places to round to (null = no rounding)
     */
    public function toNet(?TaxRate $taxRate = null, ?int $precision = self::DEFAULT_PRECISION): float
    {
        $rate = $taxRate ?? TaxRate::standard();

        $result = match ($this->taxType) {
            TaxType::Inclusive => $this->amount / (1 + $rate->toDecimal()),
            TaxType::Exclusive => $this->amount,
            TaxType::ZeroRated => $this->amount,
        };

        return $precision !== null ? \round($result, $precision) : $result;
    }

    /**
     * Check if amounts are equal (ignoring tax type).
     */
    public function amountEquals(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }

    /**
     * Create a Money instance only if the amount is non-zero, otherwise return null.
     *
     * In ShopWired, zero prices (costPrice, salePrice, comparePrice) mean "not set".
     * This normalizes that convention at the domain boundary.
     *
     * Callers must sanitize sentinel/negative values before calling this method —
     * Money enforces non-negative amounts and will reject negatives.
     *
     * @param float|null $amount The raw amount (null or 0.0 → null; must be non-negative)
     * @param TaxType $taxType Tax treatment for the value
     * @param string $currency Currency code (default: GBP)
     * @param int|null $precision Decimal places to round to (null = no rounding)
     */
    public static function nonZeroOrNull(?float $amount, TaxType $taxType, string $currency = 'GBP', ?int $precision = self::DEFAULT_PRECISION): ?self
    {
        if ($amount === null || $amount === 0.0) {
            return null;
        }

        $rounded = $precision !== null ? \round($amount, $precision) : $amount;

        return new self($rounded, $taxType, $currency);
    }

    /**
     * Check if this represents zero value.
     */
    public function isZero(): bool
    {
        return $this->amount === 0.0;
    }

    /**
     * Check if this amount is less than another Money amount.
     *
     * Compares raw amounts (ignoring tax type). For cross-tax-type comparisons,
     * convert to a common base (toGross/toNet) first.
     */
    public function isLessThan(self $other): bool
    {
        return $this->amount < $other->amount;
    }

    /**
     * Check whether a gross price survives the gross → net → gross round trip without rounding drift.
     *
     * Mirrors the frontend JS validation exactly:
     *   net = round(gross / (1 + rate), 2)
     *   reconstructed = round(net * (1 + rate), 2)
     *   return round(gross, 2) === reconstructed
     *
     * Prices that fail this check would display inconsistent values between
     * gross-based and net-based views.
     */
    public static function isVatRoundTripSafe(float $grossAmount, TaxRate $taxRate): bool
    {
        $rate = $taxRate->toDecimal();
        $gross = \round($grossAmount, self::DEFAULT_PRECISION);
        $net = \round($gross / (1 + $rate), self::DEFAULT_PRECISION);
        $reconstructed = \round($net * (1 + $rate), self::DEFAULT_PRECISION);

        return $gross === $reconstructed;
    }

    /**
     * Create a Money instance from a TaxType discriminator.
     *
     * Consolidates the common pattern of branching on TaxType to select
     * the appropriate named constructor. Used by mappers that convert
     * raw floats + tax type into domain Money values.
     */
    public static function fromTaxType(float $amount, TaxType $taxType, string $currency = 'GBP', ?int $precision = self::DEFAULT_PRECISION): self
    {
        return match ($taxType) {
            TaxType::Exclusive => self::exclusive($amount, $currency, $precision),
            TaxType::Inclusive => self::inclusive($amount, $currency, $precision),
            TaxType::ZeroRated => self::zeroRated($amount, $currency, $precision),
        };
    }
}
