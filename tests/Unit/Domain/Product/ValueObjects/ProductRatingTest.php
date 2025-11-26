<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Product\ValueObjects;

use App\Domain\Product\ValueObjects\ProductRating;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductRating::class)]
final class ProductRatingTest extends TestCase
{
    #[Test]
    public function it_creates_a_valid_instance_on_happy_path(): void
    {
        // Arrange
        $sku = 'SKU-123-ABC';
        $averageRating = 4.7;
        $numRatings = 150;

        // Act
        $productRating = new ProductRating($sku, $averageRating, $numRatings);

        // Assert
        self::assertSame($sku, $productRating->sku);
        self::assertSame($averageRating, $productRating->averageRating);
        self::assertSame($numRatings, $productRating->numRatings);
    }

    #[Test]
    public function it_allows_boundary_values(): void
    {
        // Arrange & Act
        $ratingAtLowerBoundary = new ProductRating('SKU-LOWER', 0.0, 10);
        $ratingAtUpperBoundary = new ProductRating('SKU-UPPER', 5.0, 20);
        $ratingWithZeroRatings = new ProductRating('SKU-ZERO', 0.0, 0);

        // Assert
        self::assertSame(0.0, $ratingAtLowerBoundary->averageRating);
        self::assertSame(5.0, $ratingAtUpperBoundary->averageRating);
        self::assertSame(0, $ratingWithZeroRatings->numRatings);
        self::assertSame(0.0, $ratingWithZeroRatings->averageRating, 'A product with zero ratings should logically have an average rating of 0.');
    }

    /**
     * @param array<string, mixed> $invalidData
     */
    #[Test]
    #[DataProvider('invalidConstructorArgumentsProvider')]
    public function it_throws_exception_for_invalid_constructor_arguments(array $invalidData, string $expectedExceptionMessage): void
    {
        // Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedExceptionMessage);

        // Arrange: merge invalid data with valid defaults
        $data = \array_merge([
            'sku' => 'VALID-SKU',
            'averageRating' => 4.5,
            'numRatings' => 100,
        ], $invalidData);

        // Act
        new ProductRating(...$data);
    }

    /**
     * Provides test cases for invalid constructor arguments.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidConstructorArgumentsProvider(): array
    {
        return [
            'sku is empty' => [['sku' => ''], 'SKU cannot be empty'],
            'averageRating is negative' => [['averageRating' => -0.1], 'Average rating cannot be negative'],
            'averageRating exceeds 5' => [['averageRating' => 5.001], 'Average rating cannot exceed 5'],
            'numRatings is negative' => [['numRatings' => -1], 'Number of ratings cannot be negative'],
        ];
    }

    #[Test]
    public function it_accepts_sku_with_utf8_characters(): void
    {
        // Arrange
        $sku = 'SKU-ÄÖÜ-123';

        // Act
        $productRating = new ProductRating($sku, 5.0, 1);

        // Assert
        self::assertSame($sku, $productRating->sku);
    }

    #[Test]
    public function it_accepts_non_empty_whitespace_sku_due_to_webmozart_assert_behavior(): void
    {
        // Hypothesis: Webmozart\Assert\Assert::notEmpty() uses PHP's `empty()` function,
        // which considers a string containing only whitespace as *not* empty.
        // This test documents this behavior.

        // Arrange
        $whitespaceSku = '   ';

        // Act
        $productRating = new ProductRating($whitespaceSku, 3.0, 5);

        // Assert
        self::assertSame($whitespaceSku, $productRating->sku);
    }
}
