<?php

declare(strict_types=1);

namespace App\Application\DTOs;

use Spatie\LaravelData\Data;

/**
 * Application-layer DTO for product ratings.
 *
 * This DTO represents rating data for use within the Application layer,
 * decoupled from Infrastructure concerns (API response parsing, snake_case mapping).
 *
 * Infrastructure clients map their API-specific DTOs to this common format.
 */
final class ProductRatingDTO extends Data
{
    public function __construct(
        public readonly string $sku,
        public readonly float $averageRating,
        public readonly int $numRatings,
    ) {}
}
