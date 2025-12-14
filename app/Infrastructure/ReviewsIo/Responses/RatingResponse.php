<?php

declare(strict_types=1);

namespace App\Infrastructure\ReviewsIo\Responses;

use App\Domain\Product\ValueObjects\ProductRating;
use App\Infrastructure\Contracts\DomainConvertible;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Reviews.io API Response: Product Rating Data
 *
 * Infrastructure DTO for parsing API responses only.
 * Handles snake_case mapping and required field checks.
 *
 * Business validation (rating bounds, non-negative counts, SKU format)
 * is enforced by the Domain ProductRating value object via toProductRating().
 */
#[MapInputName(SnakeCaseMapper::class)]
final class RatingResponse extends Data implements DomainConvertible
{
    public function __construct(
        #[Required]
        public readonly string $sku,
        #[Required]
        public readonly float $averageRating,
        #[Required]
        public readonly int $numRatings,
    ) {}

    /**
     * Convert to Domain Value Object.
     *
     * Maps this Infrastructure DTO to the Domain ProductRating VO,
     * which enforces business invariants.
     */
    public function toDomain(): ProductRating
    {
        return new ProductRating(
            sku: $this->sku,
            averageRating: $this->averageRating,
            numRatings: $this->numRatings,
        );
    }
}
