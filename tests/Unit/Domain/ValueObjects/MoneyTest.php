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
}
