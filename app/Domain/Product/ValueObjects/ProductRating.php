<?php

declare(strict_types=1);

namespace App\Domain\Product\ValueObjects;

use Webmozart\Assert\Assert;

final readonly class ProductRating
{
    public function __construct(
        public string $sku,
        public float $averageRating,
        public int $numRatings,
    ) {
        Assert::notEmpty($sku, 'SKU cannot be empty');
        Assert::greaterThanEq($averageRating, 0, 'Average rating cannot be negative');
        Assert::lessThanEq($averageRating, 5, 'Average rating cannot exceed 5');
        Assert::greaterThanEq($numRatings, 0, 'Number of ratings cannot be negative');
    }
}
