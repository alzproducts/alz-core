<?php

declare(strict_types=1);

namespace App\Domain\Shared\Money\ValueObjects;

use BcMath\Number;
use RoundingMode;
use Webmozart\Assert\Assert;

/**
 * Exact decimal money for the QuickBooks sync path (ADR-0022): no float ever enters or leaves it.
 */
final readonly class DecimalMoney
{
    private function __construct(
        private Number $amount,
    ) {}

    public static function fromString(string $decimal): self
    {
        self::assertDecimal($decimal, 'Decimal money must be a plain decimal string');

        return new self(new Number($decimal));
    }

    public function plus(self $other): self
    {
        return new self($this->amount->add($other->amount));
    }

    public function times(int $quantity): self
    {
        return new self($this->amount->mul($quantity));
    }

    public function dividedBy(string $divisor): self
    {
        self::assertDecimal($divisor, 'Divisor must be a plain decimal string');
        $number = new Number($divisor);
        Assert::notSame($number->compare(0), 0, 'Divisor must be non-zero');

        // intentional: an explicit scale truncates; divide at the default scale and let the caller round().
        return new self($this->amount->div($number));
    }

    public function round(int $scale): self
    {
        Assert::greaterThanEq($scale, 0, 'Scale must be non-negative');

        return new self($this->amount->round($scale, RoundingMode::HalfAwayFromZero));
    }

    public function compare(self $other): int
    {
        return $this->amount->compare($other->amount);
    }

    /**
     * Largest-remainder split: parts sum exactly to this amount; leftover units go to the largest remainders, ties to the earlier part.
     *
     * @return non-empty-list<self>
     */
    public function allocate(int $scale, int ...$weights): array
    {
        $weights = \array_values($weights);
        Assert::notEmpty($weights, 'Allocation needs at least one weight');
        $this->assertAllocatable($scale, $weights);

        $unit = (new Number(10))->pow(-$scale);
        $parts = self::largestRemainder($this->amount->div($unit), $weights);

        return \array_map(static fn(Number $units): self => new self($units->mul($unit)), $parts);
    }

    public function toString(): string
    {
        return $this->amount->value;
    }

    /**
     * @phpstan-assert numeric-string $value
     */
    private static function assertDecimal(string $value, string $message): void
    {
        // intentional: `new Number('')` is silently 0, so the pattern also rejects empty input.
        Assert::regex($value, '/^-?\d+(\.\d+)?$/', $message);
    }

    /**
     * @param list<int> $weights
     */
    private function assertAllocatable(int $scale, array $weights): void
    {
        Assert::greaterThanEq($scale, 0, 'Scale must be non-negative');
        Assert::greaterThanEq($this->amount->compare(0), 0, 'Cannot allocate a negative amount');
        Assert::same($this->amount->compare($this->amount->round($scale)), 0, 'Amount has more decimal places than the allocation scale');
        Assert::allGreaterThanEq($weights, 0, 'Allocation weights must be non-negative');
        Assert::greaterThan(\array_sum($weights), 0, 'Allocation weights must have a positive sum');
    }

    /**
     * Splits a whole number of smallest units by weight.
     *
     * @param non-empty-list<int> $weights
     *
     * @return non-empty-list<Number>
     */
    private static function largestRemainder(Number $units, array $weights): array
    {
        $weightSum = \array_sum($weights);
        $splits = \array_map(static fn(int $weight): array => $units->mul($weight)->divmod($weightSum, 0), $weights);
        $allocated = \array_reduce($splits, static fn(Number $sum, array $split): Number => $sum->add($split[0]), new Number(0));
        $leftover = (int) $units->sub($allocated)->value;

        $ranked = \array_map(static fn(int $index, array $split): array => [$index, $split[1]], \array_keys($splits), $splits);
        \usort($ranked, static function (array $a, array $b): int {
            $byRemainder = $b[1]->compare($a[1]);

            return $byRemainder !== 0 ? $byRemainder : $a[0] <=> $b[0];
        });
        $topUp = \array_column(\array_slice($ranked, 0, $leftover), 0);

        return \array_map(static fn(int $index, array $split): Number => \in_array($index, $topUp, true) ? $split[0]->add(1) : $split[0], \array_keys($splits), $splits);
    }
}
