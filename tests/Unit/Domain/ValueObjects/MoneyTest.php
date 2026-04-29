<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ValueObjects;

use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\TaxRate;
use App\Domain\ValueObjects\TaxType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webmozart\Assert\InvalidArgumentException;

#[CoversClass(Money::class)]
final class MoneyTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Factory Method Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function inclusive_creates_tax_inclusive_money(): void
    {
        $money = Money::inclusive(24.00);

        self::assertSame(TaxType::Inclusive, $money->taxType);
        self::assertSame('GBP', $money->currency);
    }

    #[Test]
    public function inclusive_from_string_preserves_high_precision_decimal(): void
    {
        $money = Money::inclusiveFromString('12.345678');

        self::assertSame(TaxType::Inclusive, $money->taxType);
        self::assertSame('GBP', $money->currency);
        // Precision preserved — not rounded to 2 decimal places by the factory
        self::assertSame(12.345678, $money->toGross(precision: null));
    }

    #[Test]
    public function inclusive_from_string_accepts_integer_string(): void
    {
        $money = Money::inclusiveFromString('100');

        self::assertSame(100.0, $money->toGross(precision: null));
    }

    #[Test]
    public function inclusive_from_string_accepts_custom_currency(): void
    {
        $money = Money::inclusiveFromString('9.99', 'USD');

        self::assertSame('USD', $money->currency);
    }

    #[Test]
    public function inclusive_from_string_rejects_non_numeric_input(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::inclusiveFromString('not-a-number');
    }

    #[Test]
    public function inclusive_from_string_rejects_negative_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::inclusiveFromString('-1.00');
    }

    #[Test]
    public function exclusive_from_string_preserves_high_precision_decimal(): void
    {
        $money = Money::exclusiveFromString('12.345678');

        self::assertSame(TaxType::Exclusive, $money->taxType);
        self::assertSame('GBP', $money->currency);
        self::assertSame(12.345678, $money->toNet(precision: null));
    }

    #[Test]
    public function exclusive_from_string_accepts_integer_string(): void
    {
        $money = Money::exclusiveFromString('100');

        self::assertSame(100.0, $money->toNet(precision: null));
    }

    #[Test]
    public function exclusive_from_string_accepts_custom_currency(): void
    {
        $money = Money::exclusiveFromString('9.99', 'USD');

        self::assertSame('USD', $money->currency);
    }

    #[Test]
    public function exclusive_from_string_rejects_non_numeric_input(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::exclusiveFromString('not-a-number');
    }

    #[Test]
    public function exclusive_from_string_rejects_negative_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::exclusiveFromString('-1.00');
    }

    #[Test]
    public function exclusive_creates_tax_exclusive_money(): void
    {
        $money = Money::exclusive(20.00);

        self::assertSame(TaxType::Exclusive, $money->taxType);
        self::assertSame('GBP', $money->currency);
    }

    #[Test]
    public function zero_rated_creates_zero_rated_money(): void
    {
        $money = Money::zeroRated(15.00);

        self::assertSame(TaxType::ZeroRated, $money->taxType);
        self::assertSame('GBP', $money->currency);
    }

    #[Test]
    public function factory_accepts_custom_currency(): void
    {
        $money = Money::inclusive(100.00, 'USD');

        self::assertSame('USD', $money->currency);
    }

    #[Test]
    public function factory_rejects_negative_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Money amount cannot be negative');

        Money::inclusive(-10.00);
    }

    #[Test]
    public function factory_rounds_to_precision(): void
    {
        $money = Money::inclusive(19.999);

        // Default precision is 2 decimal places
        self::assertSame(20.00, $money->toGross());
    }

    /*
    |--------------------------------------------------------------------------
    | toGross() Tests - Tax Inclusive Conversion
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function to_gross_returns_amount_unchanged_when_inclusive(): void
    {
        $money = Money::inclusive(24.00);

        self::assertSame(24.00, $money->toGross());
    }

    #[Test]
    public function to_gross_adds_vat_when_exclusive(): void
    {
        $money = Money::exclusive(20.00);

        // 20.00 + 20% VAT = 24.00
        self::assertSame(24.00, $money->toGross());
    }

    #[Test]
    public function to_gross_returns_amount_unchanged_when_zero_rated(): void
    {
        $money = Money::zeroRated(15.00);

        self::assertSame(15.00, $money->toGross());
    }

    #[Test]
    public function to_gross_uses_custom_tax_rate(): void
    {
        $money = Money::exclusive(100.00);

        // 100.00 + 10% = 110.00
        self::assertSame(110.00, $money->toGross(TaxRate::fromDecimal(0.10)));
    }

    /*
    |--------------------------------------------------------------------------
    | toNet() Tests - Tax Exclusive Conversion
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function to_net_removes_vat_when_inclusive(): void
    {
        $money = Money::inclusive(24.00);

        // 24.00 / 1.20 = 20.00
        self::assertSame(20.00, $money->toNet());
    }

    #[Test]
    public function to_net_returns_amount_unchanged_when_exclusive(): void
    {
        $money = Money::exclusive(20.00);

        self::assertSame(20.00, $money->toNet());
    }

    #[Test]
    public function to_net_returns_amount_unchanged_when_zero_rated(): void
    {
        $money = Money::zeroRated(15.00);

        self::assertSame(15.00, $money->toNet());
    }

    #[Test]
    public function to_net_uses_custom_tax_rate(): void
    {
        $money = Money::inclusive(110.00);

        // 110.00 / 1.10 = 100.00
        self::assertSame(100.00, $money->toNet(TaxRate::fromDecimal(0.10)));
    }

    /*
    |--------------------------------------------------------------------------
    | Comparison Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function amount_equals_returns_true_for_same_amount_and_currency(): void
    {
        $money1 = Money::inclusive(50.00);
        $money2 = Money::exclusive(50.00); // Different tax type, same amount

        self::assertTrue($money1->amountEquals($money2));
    }

    #[Test]
    public function amount_equals_returns_false_for_different_amounts(): void
    {
        $money1 = Money::inclusive(50.00);
        $money2 = Money::inclusive(60.00);

        self::assertFalse($money1->amountEquals($money2));
    }

    #[Test]
    public function amount_equals_returns_false_for_different_currencies(): void
    {
        $money1 = Money::inclusive(50.00, 'GBP');
        $money2 = Money::inclusive(50.00, 'USD');

        self::assertFalse($money1->amountEquals($money2));
    }

    #[Test]
    public function is_zero_returns_true_for_zero_amount(): void
    {
        $money = Money::inclusive(0.0);

        self::assertTrue($money->isZero());
    }

    #[Test]
    public function is_zero_returns_false_for_non_zero_amount(): void
    {
        $money = Money::inclusive(0.01);

        self::assertFalse($money->isZero());
    }

    /*
    |--------------------------------------------------------------------------
    | nonZeroOrNull() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function non_zero_or_null_returns_null_for_zero_amount(): void
    {
        $result = Money::nonZeroOrNull(0.0, TaxType::Inclusive);

        self::assertNull($result);
    }

    #[Test]
    public function non_zero_or_null_returns_null_for_null_amount(): void
    {
        $result = Money::nonZeroOrNull(null, TaxType::Inclusive);

        self::assertNull($result);
    }

    #[Test]
    public function non_zero_or_null_returns_money_for_positive_amount(): void
    {
        $result = Money::nonZeroOrNull(9.99, TaxType::Inclusive);

        self::assertNotNull($result);
        self::assertSame(9.99, $result->toGross());
        self::assertSame(TaxType::Inclusive, $result->taxType);
    }

    #[Test]
    public function non_zero_or_null_preserves_tax_type(): void
    {
        $result = Money::nonZeroOrNull(15.00, TaxType::Exclusive);

        self::assertNotNull($result);
        self::assertSame(TaxType::Exclusive, $result->taxType);
    }

    /*
    |--------------------------------------------------------------------------
    | isVatRoundTripSafe() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function vat_round_trip_safe_passes_for_clean_prices(): void
    {
        $rate = TaxRate::standard();

        self::assertTrue(Money::isVatRoundTripSafe(10.00, $rate));
        self::assertTrue(Money::isVatRoundTripSafe(24.00, $rate));
        self::assertTrue(Money::isVatRoundTripSafe(0.00, $rate));
        self::assertTrue(Money::isVatRoundTripSafe(29.99, $rate));
    }

    #[Test]
    public function vat_round_trip_safe_fails_for_prices_with_rounding_drift(): void
    {
        $rate = TaxRate::standard();

        // £0.03 → net 0.03 → reconstructed 0.04 ≠ 0.03
        self::assertFalse(Money::isVatRoundTripSafe(0.03, $rate));
        // £0.09 → net 0.08 → reconstructed 0.10 ≠ 0.09
        self::assertFalse(Money::isVatRoundTripSafe(0.09, $rate));
    }

    #[Test]
    public function vat_round_trip_safe_with_custom_tax_rate(): void
    {
        self::assertTrue(Money::isVatRoundTripSafe(1.10, TaxRate::fromPercentage(10.0)));
        self::assertTrue(Money::isVatRoundTripSafe(1.12, TaxRate::fromPercentage(10.0)));
    }

    /*
    |--------------------------------------------------------------------------
    | isLessThan() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function is_less_than_returns_true_when_amount_is_smaller(): void
    {
        $money1 = Money::inclusive(10.00);
        $money2 = Money::inclusive(20.00);

        self::assertTrue($money1->isLessThan($money2));
    }

    #[Test]
    public function is_less_than_returns_false_when_equal(): void
    {
        $money1 = Money::inclusive(10.00);
        $money2 = Money::inclusive(10.00);

        self::assertFalse($money1->isLessThan($money2));
    }

    #[Test]
    public function is_less_than_returns_false_when_amount_is_larger(): void
    {
        $money1 = Money::inclusive(20.00);
        $money2 = Money::inclusive(10.00);

        self::assertFalse($money1->isLessThan($money2));
    }

    /*
    |--------------------------------------------------------------------------
    | formatNet() / formatGross() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function format_net_returns_two_decimal_places_by_default(): void
    {
        $money = Money::exclusive(12.30);

        self::assertSame('12.30', $money->formatNet());
    }

    #[Test]
    public function format_net_enforces_trailing_zeros_for_whole_numbers(): void
    {
        $money = Money::exclusive(12.00);

        self::assertSame('12.00', $money->formatNet());
    }

    #[Test]
    public function format_net_accepts_zero_decimals(): void
    {
        $money = Money::exclusiveFromString('12.345678');

        self::assertSame('12', $money->formatNet(0));
    }

    #[Test]
    public function format_gross_adds_vat_and_formats_to_two_decimal_places(): void
    {
        $money = Money::exclusive(10.00);

        // 10.00 * 1.20 = 12.00
        self::assertSame('12.00', $money->formatGross());
    }

    #[Test]
    public function format_gross_enforces_trailing_zeros_for_inclusive_price(): void
    {
        $money = Money::inclusive(24.00);

        self::assertSame('24.00', $money->formatGross());
    }
}
