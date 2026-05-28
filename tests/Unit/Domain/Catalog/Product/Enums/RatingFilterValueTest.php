<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\Enums;

use App\Domain\Catalog\Product\Enums\RatingFilterValue;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RatingFilterValue::class)]
final class RatingFilterValueTest extends TestCase
{
    #[Test]
    public function four_stars_case_uses_string_4(): void
    {
        self::assertSame('4', RatingFilterValue::FourStars->value);
    }

    #[Test]
    public function four_and_half_stars_case_uses_string_4_point_5(): void
    {
        self::assertSame('4.5', RatingFilterValue::FourAndHalfStars->value);
    }

    /**
     * @param non-empty-string $value
     */
    #[Test]
    #[DataProvider('caseRoundTripProvider')]
    public function from_string_returns_case_for_each_known_value(string $value, RatingFilterValue $expected): void
    {
        self::assertSame($expected, RatingFilterValue::fromString($value));
    }

    /**
     * @return array<string, array{0: string, 1: RatingFilterValue}>
     */
    public static function caseRoundTripProvider(): array
    {
        return [
            'four stars' => ['4', RatingFilterValue::FourStars],
            'four and half stars' => ['4.5', RatingFilterValue::FourAndHalfStars],
        ];
    }

    #[Test]
    public function from_string_throws_for_unknown_value(): void
    {
        $this->expectException(InvalidEnumValueException::class);

        RatingFilterValue::fromString('3');
    }

    #[Test]
    public function from_postgres_array_returns_empty_list_for_empty_braces(): void
    {
        self::assertSame([], RatingFilterValue::fromPostgresArray('{}'));
    }

    #[Test]
    public function from_postgres_array_returns_empty_list_for_empty_string(): void
    {
        self::assertSame([], RatingFilterValue::fromPostgresArray(''));
    }

    #[Test]
    public function from_postgres_array_parses_single_value(): void
    {
        self::assertSame(
            [RatingFilterValue::FourStars],
            RatingFilterValue::fromPostgresArray('{4}'),
        );
    }

    #[Test]
    public function from_postgres_array_parses_both_values(): void
    {
        self::assertSame(
            [RatingFilterValue::FourStars, RatingFilterValue::FourAndHalfStars],
            RatingFilterValue::fromPostgresArray('{4,4.5}'),
        );
    }

    #[Test]
    public function from_postgres_array_throws_for_unknown_value(): void
    {
        $this->expectException(InvalidEnumValueException::class);

        RatingFilterValue::fromPostgresArray('{3}');
    }
}
