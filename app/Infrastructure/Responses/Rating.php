<?php

declare(strict_types=1);

namespace App\Infrastructure\Responses;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\Validation\Between;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Reviews.io API Response: Product Rating Data
 *
 * Represents a single product rating from the Reviews.io API.
 * This is an Infrastructure layer DTO for API responses, not a Domain concept.
 *
 * Validation rules ensure:
 * - SKU format matches product identifier standards (alphanumeric with hyphens)
 * - Rating score is between 0 and 5
 * - Number of ratings is non-negative
 * - Business rule: averageRating only valid if reviews exist
 */
#[MapInputName(SnakeCaseMapper::class)]
final class Rating extends Data
{
    public function __construct(
        #[Required, Min(value: 1), Max(value: 100)]
        public readonly string $sku,
        #[Required, Between(min: 0, max: 5)]
        public readonly float $averageRating,
        #[Required, Min(value: 0)]
        public readonly int $numRatings,
    ) {}
}
