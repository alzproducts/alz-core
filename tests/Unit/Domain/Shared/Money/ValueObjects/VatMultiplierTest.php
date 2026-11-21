<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared\Money\ValueObjects;

use App\Domain\Shared\Money\ValueObjects\VatMultiplier;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(VatMultiplier::class)]
final class VatMultiplierTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function rates(): iterable
    {
        yield 'six-decimal 20%' => ['20.000000', 20, '1.20'];
        yield 'truncates 19.99' => ['19.99', 19, '1.19'];
        yield 'below 1%' => ['0.5', 0, '1.00'];
        yield 'zero' => ['0', 0, '1.00'];
        yield 'single digit' => ['5', 5, '1.05'];
        yield 'highest rate' => ['99.99', 99, '1.99'];
        yield 'many nines below 100' => ['99.9999999999999999', 99, '1.99'];
    }

    #[Test]
    #[DataProvider('rates')]
    public function it_truncates_the_rate_to_a_whole_percent(string $rate, int $truncated, string $multiplier): void
    {
        $vat = VatMultiplier::fromTruncatedRate($rate);

        self::assertSame($truncated, $vat->truncatedRate());
        self::assertSame($multiplier, $vat->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidRates(): iterable
    {
        yield 'negative' => ['-1'];
        yield 'one hundred' => ['100'];
        yield 'above one hundred' => ['100.5'];
        yield 'not a number' => ['abc'];
        yield 'empty' => [''];
        yield 'exponent' => ['2e1'];
        yield 'trailing text' => ['20%'];
        yield 'leading text' => ['x20'];
    }

    #[Test]
    #[DataProvider('invalidRates')]
    public function it_rejects_rates_outside_zero_to_below_one_hundred(string $rate): void
    {
        $this->expectException(InvalidArgumentException::class);

        VatMultiplier::fromTruncatedRate($rate);
    }
}
