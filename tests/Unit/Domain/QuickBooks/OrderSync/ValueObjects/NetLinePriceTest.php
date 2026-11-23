<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\QuickBooks\OrderSync\ValueObjects;

use App\Domain\QuickBooks\OrderSync\Enums\SupportedVatRate;
use App\Domain\QuickBooks\OrderSync\ValueObjects\NetLinePrice;
use App\Domain\Shared\Money\ValueObjects\DecimalMoney;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NetLinePrice::class)]
final class NetLinePriceTest extends TestCase
{
    #[Test]
    public function it_divides_the_gross_unit_price_by_the_standard_multiplier_at_7dp_and_rounds_the_amount_to_2dp(): void
    {
        $price = NetLinePrice::fromGross(
            DecimalMoney::fromString('4.158333'),
            DecimalMoney::fromString('0.831667'),
            SupportedVatRate::Standard,
            3,
        );

        self::assertSame('4.1583333', $price->unitPrice->toString());
        self::assertSame('12.47', $price->amount->toString());
    }

    #[Test]
    public function it_keeps_the_gross_price_at_the_zero_rate(): void
    {
        $price = NetLinePrice::fromGross(
            DecimalMoney::fromString('12.5'),
            DecimalMoney::fromString('0'),
            SupportedVatRate::Zero,
            2,
        );

        self::assertSame('12.5000000', $price->unitPrice->toString());
        self::assertSame('25.00', $price->amount->toString());
    }

    #[Test]
    public function it_rejects_a_non_positive_quantity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        NetLinePrice::fromGross(
            DecimalMoney::fromString('1'),
            DecimalMoney::fromString('0'),
            SupportedVatRate::Zero,
            0,
        );
    }
}
