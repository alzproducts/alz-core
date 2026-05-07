<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\Enums;

use App\Domain\Catalog\Product\Enums\PopularityBucket;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PopularityBucket::class)]
final class PopularityBucketTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | rankRange() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function most_popular_returns_one_to_three_range(): void
    {
        self::assertSame([1, 3], PopularityBucket::MostPopular->rankRange());
    }

    #[Test]
    public function least_popular_returns_ten_to_twelve_range(): void
    {
        self::assertSame([10, 12], PopularityBucket::LeastPopular->rankRange());
    }

    /*
    |--------------------------------------------------------------------------
    | Enum Structure Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function enum_has_exactly_two_cases(): void
    {
        self::assertCount(2, PopularityBucket::cases());
    }

    #[Test]
    public function enum_values_match_expected(): void
    {
        self::assertSame('most_popular', PopularityBucket::MostPopular->value);
        self::assertSame('least_popular', PopularityBucket::LeastPopular->value);
    }
}
