<?php

declare(strict_types=1);

namespace Database\Seeders\OrderSlice;

/**
 * A distribution of values with integer weights. `suppressed` is weight the profile withheld (buckets under its
 * minimum count); a draw that lands in it returns null, and the caller falls back to a synthetic value.
 *
 * @template-covariant T
 */
final readonly class Weighted
{
    /**
     * @param list<array{T, int}> $entries
     */
    public function __construct(
        public array $entries,
        public int $suppressed = 0,
    ) {}

    /**
     * Every draw lands in suppressed weight, so every value falls back to a synthetic one.
     *
     * @return self<never>
     */
    public static function suppressedOnly(): self
    {
        /** @var list<array{never, int}> $entries */
        $entries = [];

        return new self($entries, 1);
    }

    public function totalWeight(): int
    {
        return \array_sum(\array_column($this->entries, 1)) + $this->suppressed;
    }
}
