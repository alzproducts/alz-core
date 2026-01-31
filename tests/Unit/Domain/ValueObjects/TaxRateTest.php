<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ValueObjects;

use App\Domain\ValueObjects\TaxRate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webmozart\Assert\InvalidArgumentException;

#[CoversClass(TaxRate::class)]
final class TaxRateTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Factory Method Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function from_percentage_creates_tax_rate(): void
    {
        $rate = TaxRate::fromPercentage(20.0);

        self::assertSame(20.0, $rate->percentage);
    }

    #[Test]
    public function from_decimal_converts_to_percentage(): void
    {
        $rate = TaxRate::fromDecimal(0.20);

        self::assertSame(20.0, $rate->percentage);
    }

    #[Test]
    public function standard_returns_uk_vat_rate(): void
    {
        $rate = TaxRate::standard();

        self::assertSame(20.0, $rate->percentage);
    }

    #[Test]
    public function zero_returns_zero_rate(): void
    {
        $rate = TaxRate::zero();

        self::assertSame(0.0, $rate->percentage);
    }

    #[Test]
    public function rejects_negative_percentage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Tax rate cannot be negative');

        TaxRate::fromPercentage(-5.0);
    }

    /*
    |--------------------------------------------------------------------------
    | Conversion Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function to_decimal_converts_percentage_to_decimal(): void
    {
        $rate = TaxRate::fromPercentage(20.0);

        self::assertSame(0.20, $rate->toDecimal());
    }

    #[Test]
    public function to_decimal_handles_zero_rate(): void
    {
        $rate = TaxRate::zero();

        self::assertSame(0.0, $rate->toDecimal());
    }

    /*
    |--------------------------------------------------------------------------
    | Boolean Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function is_zero_returns_true_for_zero_rate(): void
    {
        $rate = TaxRate::zero();

        self::assertTrue($rate->isZero());
    }

    #[Test]
    public function is_zero_returns_false_for_non_zero_rate(): void
    {
        $rate = TaxRate::standard();

        self::assertFalse($rate->isZero());
    }

    #[Test]
    public function is_standard_returns_true_for_uk_vat(): void
    {
        $rate = TaxRate::standard();

        self::assertTrue($rate->isStandard());
    }

    #[Test]
    public function is_standard_returns_false_for_other_rates(): void
    {
        $rate = TaxRate::fromPercentage(15.0);

        self::assertFalse($rate->isStandard());
    }
}
