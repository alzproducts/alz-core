<?php

declare(strict_types=1);

namespace App\Infrastructure\Responses;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Reviews.io API Response: Product Rating Data
 *
 * Represents a single product rating from the Reviews.io API.
 * This is an Infrastructure layer DTO for API responses, not a Domain concept.
 *
 * Attributes:
 * - sku: Product SKU from API (maps from snake_case 'sku')
 * - averageRating: Average rating score (maps from 'average_rating')
 * - numRatings: Number of reviews (maps from 'num_ratings')
 */
#[MapInputName(SnakeCaseMapper::class)]
final class Rating extends Data
{
    public function __construct(
        public readonly string $sku,
        public readonly float $averageRating,
        public readonly int $numRatings,
    ) {}
}
