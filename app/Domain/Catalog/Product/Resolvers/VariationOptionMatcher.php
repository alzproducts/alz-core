<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Resolvers;

use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationOption;

/**
 * Finds a variation with matching options from a set of candidates.
 *
 * Matching is based on normalized option_name + value_name pairs
 * (case-insensitive, sorted alphabetically). Returns the full matched
 * variation so the caller can extract any field they need.
 *
 * @template-pattern Domain Service
 */
final readonly class VariationOptionMatcher
{
    /**
     * Find a candidate variation whose options match the target.
     *
     * @param ProductVariation $target The variation to match
     * @param list<ProductVariation> $candidates Variations to search through
     *
     * @return ProductVariation|null The matched variation, or null if no match
     */
    public function findMatch(ProductVariation $target, array $candidates): ?ProductVariation
    {
        $targetKey = self::buildOptionKey($target);

        if ($targetKey === '') {
            return null;
        }

        foreach ($candidates as $candidate) {
            if (self::buildOptionKey($candidate) === $targetKey) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Build a normalized, comparable key from a variation's options.
     *
     * Sorts by option name then concatenates "optionname:valuename" pairs,
     * all lowercased, to produce a stable comparison key.
     */
    private static function buildOptionKey(ProductVariation $variation): string
    {
        if ($variation->options === []) {
            return '';
        }

        $pairs = \array_map(
            static fn(ProductVariationOption $opt): string => \mb_strtolower($opt->optionName) . ':' . \mb_strtolower($opt->valueName),
            $variation->options,
        );

        \sort($pairs);

        return \implode('|', $pairs);
    }
}
