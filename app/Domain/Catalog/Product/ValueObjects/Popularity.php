<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * Popularity rank from the snapshot pipeline (catalog.product_popularity_ranking_latest).
 *
 * rank = 1 is the most popular; rank = max is the least popular seller / non-seller floor.
 * `max` comes from the config row (algorithm_version) that produced the rank — pairing
 * stale snapshots with a live config's max_rank would violate the rank ≤ max invariant.
 *
 * Separate from ShopWired's sort_order, which is a write channel that today mirrors
 * this rank but will diverge once sale/featured boost logic lands.
 */
final readonly class Popularity
{
    public function __construct(
        public int $rank,
        public int $max,
    ) {
        Assert::greaterThanEq($rank, 1);
        Assert::lessThanEq($rank, $max);
        Assert::range($max, 2, 100);
    }

    /**
     * Build from raw row values — null if either side of the LEFT JOIN missed.
     */
    public static function fromRank(?int $rank, ?int $max): ?self
    {
        return $rank === null || $max === null ? null : new self($rank, $max);
    }

    /**
     * Discrete popularity level in 1..$segments for bar-style visuals.
     *
     * Maps the continuous rank (1..max) onto an integer scale where higher = more
     * popular, inverting "rank 1 is best" so consumers can treat the returned value
     * as signal strength directly.
     *
     * When $segments > $max, some levels become unreachable (e.g. max=2, segments=5
     * yields only levels 3 and 5) — intrinsic to compressing fewer rank positions
     * into more visual segments.
     */
    public function level(int $segments = 5): int
    {
        Assert::greaterThanEq($segments, 1);

        $strength = $this->max - $this->rank + 1;

        return (int) \ceil($strength / $this->max * $segments);
    }

    /**
     * @return array{rank: int, max: int, level: int}
     */
    public function toArray(): array
    {
        return [
            'rank' => $this->rank,
            'max' => $this->max,
            'level' => $this->level(),
        ];
    }
}
