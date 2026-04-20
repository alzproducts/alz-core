<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\Product\ValueObjects\Popularity;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Popularity::class)]
final class PopularityTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Invariants
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function accepts_rank_one_as_lower_bound(): void
    {
        $popularity = new Popularity(rank: 1, max: 12);

        self::assertSame(1, $popularity->rank);
        self::assertSame(12, $popularity->max);
    }

    #[Test]
    public function accepts_rank_equal_to_max(): void
    {
        $popularity = new Popularity(rank: 12, max: 12);

        self::assertSame(12, $popularity->rank);
    }

    #[Test]
    public function rejects_rank_below_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Popularity(rank: 0, max: 12);
    }

    #[Test]
    public function rejects_rank_greater_than_max(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Popularity(rank: 13, max: 12);
    }

    #[Test]
    public function rejects_max_below_two(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Popularity(rank: 1, max: 1);
    }

    #[Test]
    public function rejects_max_above_one_hundred(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Popularity(rank: 1, max: 101);
    }

    #[Test]
    public function accepts_max_at_upper_bound(): void
    {
        $popularity = new Popularity(rank: 50, max: 100);

        self::assertSame(100, $popularity->max);
    }

    /*
    |--------------------------------------------------------------------------
    | fromRank — nullable factory
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function from_rank_returns_null_when_rank_is_null(): void
    {
        self::assertNull(Popularity::fromRank(null, 12));
    }

    #[Test]
    public function from_rank_returns_null_when_max_is_null(): void
    {
        self::assertNull(Popularity::fromRank(3, null));
    }

    #[Test]
    public function from_rank_returns_null_when_both_are_null(): void
    {
        self::assertNull(Popularity::fromRank(null, null));
    }

    #[Test]
    public function from_rank_constructs_when_both_provided(): void
    {
        $popularity = Popularity::fromRank(3, 12);

        self::assertNotNull($popularity);
        self::assertSame(3, $popularity->rank);
        self::assertSame(12, $popularity->max);
    }

    /*
    |--------------------------------------------------------------------------
    | level — discrete fill level for visual bars
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function level_top_rank_fills_all_segments(): void
    {
        $popularity = new Popularity(rank: 1, max: 12);

        self::assertSame(5, $popularity->level(5));
    }

    #[Test]
    public function level_bottom_rank_fills_single_segment(): void
    {
        $popularity = new Popularity(rank: 12, max: 12);

        self::assertSame(1, $popularity->level(5));
    }

    #[Test]
    public function level_middle_rank_fills_middle_segment(): void
    {
        $popularity = new Popularity(rank: 6, max: 12);

        self::assertSame(3, $popularity->level(5));
    }

    #[Test]
    public function level_with_single_segment_always_returns_one(): void
    {
        $popularity = new Popularity(rank: 1, max: 12);

        self::assertSame(1, $popularity->level(1));
    }

    #[Test]
    public function level_defaults_to_five_segments(): void
    {
        $popularity = new Popularity(rank: 1, max: 12);

        self::assertSame(5, $popularity->level());
    }

    #[Test]
    public function level_rejects_zero_segments(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Popularity(rank: 1, max: 12))->level(0);
    }

    /*
    |--------------------------------------------------------------------------
    | toArray — wire-shape serialization
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function to_array_includes_rank_max_and_level(): void
    {
        $popularity = new Popularity(rank: 3, max: 12);

        self::assertSame(
            ['rank' => 3, 'max' => 12, 'level' => 5],
            $popularity->toArray(),
        );
    }
}
