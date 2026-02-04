<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ContactSubmission\Enums;

use App\Domain\ContactSubmission\Enums\ProductSource;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ProductSource Enum Unit Tests.
 *
 * Tests the source identification for selected products in contact forms.
 * This indicates whether a product came from browsing history or order history.
 */
#[CoversClass(ProductSource::class)]
final class ProductSourceTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | label() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('labelProvider')]
    public function it_returns_correct_label_for_each_case(ProductSource $source, string $expectedLabel): void
    {
        self::assertSame($expectedLabel, $source->label());
    }

    /**
     * @return array<string, array{ProductSource, string}>
     */
    public static function labelProvider(): array
    {
        return [
            'RecentlyViewed returns Recently Viewed' => [ProductSource::RecentlyViewed, 'Recently Viewed'],
            'RecentlyOrdered returns Recently Ordered' => [ProductSource::RecentlyOrdered, 'Recently Ordered'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | fromValue() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('validValueProvider')]
    public function it_creates_from_valid_backing_value(string $value, ProductSource $expectedSource): void
    {
        self::assertSame($expectedSource, ProductSource::fromValue($value));
    }

    /**
     * @return array<string, array{string, ProductSource}>
     */
    public static function validValueProvider(): array
    {
        return [
            'recently_viewed' => ['recently_viewed', ProductSource::RecentlyViewed],
            'recently_ordered' => ['recently_ordered', ProductSource::RecentlyOrdered],
        ];
    }

    #[Test]
    public function it_throws_for_invalid_backing_value(): void
    {
        $this->expectException(InvalidEnumValueException::class);

        ProductSource::fromValue('invalid_source');
    }

    #[Test]
    public function it_throws_for_empty_value(): void
    {
        $this->expectException(InvalidEnumValueException::class);

        ProductSource::fromValue('');
    }

    /*
    |--------------------------------------------------------------------------
    | Enum Structure Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function enum_has_exactly_two_cases(): void
    {
        self::assertCount(2, ProductSource::cases());
    }

    #[Test]
    public function backing_values_use_snake_case(): void
    {
        foreach (ProductSource::cases() as $source) {
            self::assertMatchesRegularExpression(
                '/^[a-z_]+$/',
                $source->value,
                "Backing value '{$source->value}' should be snake_case",
            );
        }
    }
}
