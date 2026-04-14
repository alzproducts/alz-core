<?php

declare(strict_types=1);

namespace App\Domain\Catalog\RelatedProducts\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * Algorithm parameters for the related products computation.
 *
 * Hydrated from the versioned `catalog.related_products_algorithm_params` table.
 * Immutable — all properties are read-only.
 */
final readonly class RelatedProductsAlgorithmParams
{
    public function __construct(
        public float $categoryWeight,
        public float $titleWeight,
        public float $popularityWeight,
        public int $maxResults,
        public float $minContentScore,
        public float $defaultPopularity,
        public bool $excludeCompareList,
    ) {
        Assert::greaterThan($categoryWeight, 0, 'category_weight must be positive');
        Assert::greaterThan($titleWeight, 0, 'title_weight must be positive');
        Assert::greaterThan($popularityWeight, 0, 'popularity_weight must be positive');
        Assert::range($maxResults, 2, 20, 'max_results must be between 2 and 20');
        Assert::greaterThanEq($minContentScore, 0, 'min_content_score must be non-negative');
        Assert::greaterThan($defaultPopularity, 0, 'default_popularity must be positive');
    }
}
