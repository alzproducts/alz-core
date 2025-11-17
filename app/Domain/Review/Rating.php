<?php

declare(strict_types=1);

namespace App\Domain\Review;

use Spatie\LaravelData\Data;

final class Rating extends Data
{
    public function __construct(
        public readonly string $sku,
        public readonly float $averageRating,
        public readonly int $numRatings,
    ) {}
}
