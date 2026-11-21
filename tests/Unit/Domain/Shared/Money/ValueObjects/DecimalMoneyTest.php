<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Shared\Money\ValueObjects;

use App\Domain\Shared\Money\ValueObjects\DecimalMoney;
use App\Domain\Shared\Money\ValueObjects\VatMultiplier;
use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DecimalMoney::class)]
final class DecimalMoneyTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function validDecimals(): iterable
    {
        yield 'integer' => ['12'];
        yield 'scale kept' => ['12.340000'];
        yield 'negative' => ['-0.125'];
        yield 'zero' => ['0'];
    }

    #[Test]
    #[DataProvider('validDecimals')]
    public function it_keeps_the_decimal_string_and_its_scale(string $decimal): void
    {
        self::assertSame($decimal, DecimalMoney::fromString($decimal)->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDecimals(): iterable
    {
        yield 'empty' => [''];
        yield 'exponent' => ['1e3'];
        yield 'leading text' => ['x1'];
        yield 'trailing text' => ['1x'];
        yield 'bare point' => ['1.'];
        yield 'leading plus' => ['+1'];
        yield 'not a number' => ['abc'];
    }

    #[Test]
    #[DataProvider('invalidDecimals')]
    public function it_rejects_anything_but_a_plain_decimal_string(string $decimal): void
    {
        $this->expectException(InvalidArgumentException::class);

        DecimalMoney::fromString($decimal);
    }

    #[Test]
    public function plus_and_times_keep_scale(): void
    {
        self::assertSame('0.305', self::money('0.10')->plus(self::money('0.205'))->toString());
        self::assertSame('0.999', self::money('0.333')->times(3)->toString());
    }

    #[Test]
    public function division_rounds_up_at_seven_places_instead_of_truncating(): void
    {
        self::assertSame('0.6666667', self::money('2')->dividedBy('3')->round(7)->toString());
    }

    #[Test]
    public function a_gross_price_divided_by_the_vat_multiplier_gives_the_net_price(): void
    {
        $multiplier = VatMultiplier::fromTruncatedRate('20.000000')->toString();

        self::assertSame('10.0000000', self::money('12.00')->dividedBy($multiplier)->round(7)->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDivisors(): iterable
    {
        yield 'zero' => ['0'];
        yield 'zero with scale' => ['0.00'];
        yield 'empty' => [''];
        yield 'exponent' => ['1e3'];
    }

    #[Test]
    #[DataProvider('invalidDivisors')]
    public function it_rejects_a_zero_or_malformed_divisor(string $divisor): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::money('1')->dividedBy($divisor);
    }

    #[Test]
    public function a_negative_divisor_divides(): void
    {
        self::assertSame('-2.00', self::money('1')->dividedBy('-0.5')->round(2)->toString());
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function roundings(): iterable
    {
        yield 'half up' => ['0.125', 2, '0.13'];
        yield 'half away from zero when negative' => ['-0.125', 2, '-0.13'];
        yield 'below half' => ['0.124', 2, '0.12'];
        yield 'to whole units' => ['2.5', 0, '3'];
        yield 'pads scale' => ['5', 2, '5.00'];
    }

    #[Test]
    #[DataProvider('roundings')]
    public function it_rounds_half_away_from_zero(string $amount, int $scale, string $expected): void
    {
        self::assertSame($expected, self::money($amount)->round($scale)->toString());
    }

    #[Test]
    public function it_rejects_a_negative_rounding_scale(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::money('15')->round(-1);
    }

    #[Test]
    public function compare_orders_amounts_regardless_of_scale(): void
    {
        self::assertSame(-1, self::money('1.00')->compare(self::money('1.01')));
        self::assertSame(0, self::money('1.00')->compare(self::money('1')));
        self::assertSame(1, self::money('1.01')->compare(self::money('1.00')));
    }

    /**
     * @return iterable<string, array{string, int, list<int>, list<string>}>
     */
    public static function allocations(): iterable
    {
        yield 'uneven three ways' => ['1.00', 2, [1, 1, 1], ['0.34', '0.33', '0.33']];
        yield 'unequal weights' => ['1.00', 2, [1, 2, 4], ['0.14', '0.29', '0.57']];
        yield 'leftover to largest remainder, not first' => ['1.00', 2, [4, 2, 1], ['0.57', '0.29', '0.14']];
        yield 'two leftovers, ties to earlier' => ['0.05', 2, [1, 1, 1], ['0.02', '0.02', '0.01']];
        yield 'exact split' => ['0.90', 2, [1, 1, 1], ['0.30', '0.30', '0.30']];
        yield 'zero weight gets nothing' => ['1.00', 2, [1, 0, 1], ['0.50', '0.00', '0.50']];
        yield 'single part' => ['7.25', 2, [3], ['7.25']];
        yield 'whole units' => ['10', 0, [1, 1, 1], ['4', '3', '3']];
        yield 'total below allocation scale' => ['1.0', 3, [1, 1, 1], ['0.334', '0.333', '0.333']];
        yield 'zero total' => ['0.00', 2, [1, 1], ['0.00', '0.00']];
    }

    /**
     * @param list<int> $weights
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('allocations')]
    public function allocation_parts_sum_exactly_to_the_total(string $total, int $scale, array $weights, array $expected): void
    {
        $parts = self::money($total)->allocate($scale, ...$weights);

        self::assertSame($expected, \array_map(static fn(DecimalMoney $part): string => $part->toString(), $parts));
        $sum = \array_reduce($parts, static fn(DecimalMoney $carry, DecimalMoney $part): DecimalMoney => $carry->plus($part), self::money('0'));
        self::assertSame(0, $sum->compare(self::money($total)));
    }

    /**
     * @return iterable<string, array{Closure(): mixed}>
     */
    public static function invalidAllocations(): iterable
    {
        yield 'negative total' => [static fn(): array => self::money('-1.00')->allocate(2, 1, 1)];
        yield 'total finer than scale' => [static fn(): array => self::money('1.005')->allocate(2, 1, 1)];
        yield 'no weights' => [static fn(): array => self::money('1.00')->allocate(2)];
        yield 'negative weight' => [static fn(): array => self::money('1.00')->allocate(2, 2, -1)];
        yield 'zero weight sum' => [static fn(): array => self::money('1.00')->allocate(2, 0, 0)];
        yield 'negative scale' => [static fn(): array => self::money('10')->allocate(-1, 1, 1)];
    }

    /**
     * @param Closure(): mixed $allocation
     */
    #[Test]
    #[DataProvider('invalidAllocations')]
    public function it_rejects_an_invalid_allocation(Closure $allocation): void
    {
        $this->expectException(InvalidArgumentException::class);

        $allocation();
    }

    private static function money(string $decimal): DecimalMoney
    {
        return DecimalMoney::fromString($decimal);
    }
}
