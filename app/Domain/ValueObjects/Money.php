<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

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
     * Check if this represents zero value.
     */
    public function isZero(): bool
    {
        return $this->amount === 0.0;
    }
}
