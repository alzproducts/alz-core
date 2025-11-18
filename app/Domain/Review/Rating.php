<?php

declare(strict_types=1);

namespace App\Domain\Review;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapInputName(SnakeCaseMapper::class)]
final class Rating extends Data
{
    public function __construct(
        public readonly string $sku,
        public readonly float $averageRating,
        public readonly int $numRatings,
    ) {}
}
