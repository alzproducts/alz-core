<?php

declare(strict_types=1);

namespace App\Domain\Shared\Money\ValueObjects;

use BcMath\Number;
use Webmozart\Assert\Assert;

/**
 * Gross-to-net divisor for the QuickBooks sync: the VAT rate truncated to a whole percent, as (100 + rate) / 100.
 */
final readonly class VatMultiplier
{
    private function __construct(
        private int $truncatedRate,
    ) {
        Assert::lessThan($truncatedRate, 100, 'VAT rate must be below 100');
    }

    public static function fromTruncatedRate(string $rate): self
    {
        self::assertRate($rate);

        // intentional: floor() on the decimal, not an (int) cast, which goes through a float ('99.9999999999999999' → 100).
        return new self((int) (new Number($rate))->floor()->value);
    }

    public function truncatedRate(): int
    {
        return $this->truncatedRate;
    }

    public function toString(): string
    {
        return \sprintf('1.%02d', $this->truncatedRate);
    }

    /**
     * @phpstan-assert numeric-string $rate
     */
    private static function assertRate(string $rate): void
    {
        Assert::regex($rate, '/^\d+(\.\d+)?$/', 'VAT rate must be a non-negative decimal string');
    }
}
