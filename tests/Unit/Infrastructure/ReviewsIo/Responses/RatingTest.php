<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\ReviewsIo\Responses;

use App\Domain\Product\ValueObjects\ProductRating;
use App\Infrastructure\ReviewsIo\Responses\Rating;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Rating DTO Unit Tests
 *
 * Tests the Infrastructure layer Rating DTO covering:
 * - Snake_case to camelCase property mapping (Spatie LaravelData)
 * - Conversion to Domain ProductRating Value Object
 * - Type and value preservation
 */
#[CoversClass(Rating::class)]
final class RatingTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Snake_case Mapping Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_maps_snake_case_api_response_to_camel_case_properties(): void
    {
        $apiResponse = [
            'sku' => 'FLP-01',
            'average_rating' => 4.5,
            'num_ratings' => 362,
        ];

        $rating = Rating::from($apiResponse);

        self::assertSame('FLP-01', $rating->sku);
        self::assertSame(4.5, $rating->averageRating);
        self::assertSame(362, $rating->numRatings);
    }

    #[Test]
    public function it_preserves_float_precision_for_average_rating(): void
    {
        $apiResponse = [
            'sku' => 'PRECISE-SKU',
            'average_rating' => 4.123456,
            'num_ratings' => 50,
        ];

        $rating = Rating::from($apiResponse);

        self::assertSame(4.123456, $rating->averageRating);
        self::assertIsFloat($rating->averageRating);
    }

    #[Test]
    public function it_preserves_integer_type_for_num_ratings(): void
    {
        $apiResponse = [
            'sku' => 'INT-SKU',
            'average_rating' => 3.0,
            'num_ratings' => 1000,
        ];

        $rating = Rating::from($apiResponse);

        self::assertSame(1000, $rating->numRatings);
        self::assertIsInt($rating->numRatings);
    }

    /*
    |--------------------------------------------------------------------------
    | Domain Conversion Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_converts_to_domain_product_rating_value_object(): void
    {
        $apiResponse = [
            'sku' => 'PROD-XYZ',
            'average_rating' => 4.75,
            'num_ratings' => 75,
        ];

        $rating = Rating::from($apiResponse);
        $productRating = $rating->toProductRating();

        self::assertInstanceOf(ProductRating::class, $productRating);
        self::assertSame('PROD-XYZ', $productRating->sku);
        self::assertSame(4.75, $productRating->averageRating);
        self::assertSame(75, $productRating->numRatings);
    }

    #[Test]
    public function it_converts_to_domain_with_zero_ratings(): void
    {
        $apiResponse = [
            'sku' => 'NO-REVIEWS-SKU',
            'average_rating' => 0.0,
            'num_ratings' => 0,
        ];

        $rating = Rating::from($apiResponse);
        $productRating = $rating->toProductRating();

        self::assertInstanceOf(ProductRating::class, $productRating);
        self::assertSame(0.0, $productRating->averageRating);
        self::assertSame(0, $productRating->numRatings);
    }

    #[Test]
    public function it_converts_to_domain_with_max_rating(): void
    {
        $apiResponse = [
            'sku' => 'PERFECT-SKU',
            'average_rating' => 5.0,
            'num_ratings' => 100,
        ];

        $rating = Rating::from($apiResponse);
        $productRating = $rating->toProductRating();

        self::assertSame(5.0, $productRating->averageRating);
    }

    /*
    |--------------------------------------------------------------------------
    | Domain Invariant Enforcement Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_when_converting_empty_sku_to_domain(): void
    {
        $apiResponse = [
            'sku' => '',
            'average_rating' => 3.0,
            'num_ratings' => 10,
        ];

        $rating = Rating::from($apiResponse);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SKU cannot be empty');

        $rating->toProductRating();
    }

    #[Test]
    public function it_throws_when_converting_negative_rating_to_domain(): void
    {
        $apiResponse = [
            'sku' => 'NEG-RATING-SKU',
            'average_rating' => -0.1,
            'num_ratings' => 10,
        ];

        $rating = Rating::from($apiResponse);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Average rating cannot be negative');

        $rating->toProductRating();
    }

    #[Test]
    public function it_throws_when_converting_rating_above_five_to_domain(): void
    {
        $apiResponse = [
            'sku' => 'HIGH-RATING-SKU',
            'average_rating' => 5.1,
            'num_ratings' => 10,
        ];

        $rating = Rating::from($apiResponse);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Average rating cannot exceed 5');

        $rating->toProductRating();
    }

    #[Test]
    public function it_throws_when_converting_negative_num_ratings_to_domain(): void
    {
        $apiResponse = [
            'sku' => 'NEG-COUNT-SKU',
            'average_rating' => 3.0,
            'num_ratings' => -5,
        ];

        $rating = Rating::from($apiResponse);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Number of ratings cannot be negative');

        $rating->toProductRating();
    }
}
