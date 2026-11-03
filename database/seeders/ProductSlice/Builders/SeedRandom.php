<?php

declare(strict_types=1);

namespace Database\Seeders\ProductSlice\Builders;

use Faker\Generator;
use UnexpectedValueException;

/**
 * Every random draw in the slice goes through the seeded Faker generator, so one seed fixes the whole slice.
 */
final readonly class SeedRandom
{
    /** 2026-01-01 00:00:00 UTC */
    public const int BASE_TIMESTAMP = 1_767_225_600;

    private const int TIMESTAMP_SPAN_SECONDS = 300 * 86_400;

    public function __construct(
        private Generator $faker,
    ) {}

    public function int(int $min, int $max): int
    {
        return $this->faker->numberBetween($min, $max);
    }

    public function uuid(): string
    {
        return $this->faker->uuid();
    }

    /**
     * A positive amount with exactly two decimal places.
     */
    public function money(int $minPence, int $maxPence): float
    {
        return $this->int($minPence, $maxPence) / 100;
    }

    /**
     * A positive amount whose third decimal place is non-zero.
     */
    public function moneyOverTwoDecimals(int $minPence, int $maxPence): float
    {
        return ($this->int($minPence, $maxPence) * 10 + $this->int(1, 9)) / 1000;
    }

    public function timestamp(): string
    {
        return self::formatTimestamp(self::BASE_TIMESTAMP + $this->int(0, self::TIMESTAMP_SPAN_SECONDS));
    }

    public static function formatTimestamp(int $unixSeconds): string
    {
        return \gmdate('Y-m-d H:i:s', $unixSeconds);
    }

    /**
     * @template T
     *
     * @param list<T> $items
     *
     * @return list<T>
     */
    public function shuffle(array $items): array
    {
        /** @var list<T> $shuffled */
        $shuffled = \array_values($this->faker->shuffleArray($items));

        return $shuffled;
    }

    /**
     * @template T
     *
     * @param list<T> $items
     *
     * @return T
     */
    public function pick(array $items): mixed
    {
        if ($items === []) {
            throw new UnexpectedValueException('Cannot pick from an empty pool');
        }

        return $items[$this->int(0, \count($items) - 1)] ?? throw new UnexpectedValueException('Pick fell outside its pool');
    }

    /**
     * @template T
     *
     * @param list<T> $pool
     *
     * @return list<T>
     */
    public function sample(array $pool, int $count): array
    {
        if ($count > \count($pool)) {
            throw new UnexpectedValueException('Sample is larger than its pool');
        }

        return \array_slice($this->shuffle($pool), 0, $count);
    }

    /**
     * A random subset of `$pool` as a membership set.
     *
     * @param list<int> $pool
     *
     * @return array<int, true>
     */
    public function subset(array $pool, int $count): array
    {
        return self::toSet($this->sample($pool, $count));
    }

    /**
     * @param list<int> $members
     *
     * @return array<int, true>
     */
    public static function toSet(array $members): array
    {
        return \array_fill_keys($members, true);
    }

    /**
     * @param list<int> $pool
     * @param array<int, true> ...$excluded
     *
     * @return list<int>
     */
    public static function without(array $pool, array ...$excluded): array
    {
        return \array_values(\array_filter(
            $pool,
            static fn(int $member): bool => !\array_any($excluded, static fn(array $set): bool => isset($set[$member])),
        ));
    }

    /**
     * Expands a `bucket => count` distribution into one entry per counted item, e.g. `[2 => 3]` to `[2, 2, 2]`.
     *
     * @param array<int, int> $histogram
     *
     * @return list<int>
     */
    public static function expand(array $histogram): array
    {
        $values = [];

        foreach ($histogram as $bucket => $count) {
            \array_push($values, ...\array_fill(0, $count, $bucket));
        }

        return $values;
    }
}
