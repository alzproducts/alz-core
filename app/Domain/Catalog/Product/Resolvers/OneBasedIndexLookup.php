<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Resolvers;

/**
 * Look up an item in a list using a 1-based index.
 *
 * ShopWired stores variation `imageIndex` as 1-based (matching its UI display).
 * Centralised here so both `VariationImageResolver` (typed `ProductImage` lookups)
 * and `VariationListItem::resolveImage()` (raw array lookups before VO hydration)
 * share one source of truth for the index semantics.
 */
final readonly class OneBasedIndexLookup
{
    /**
     * Return the item at the given 1-based index, or null when out of range.
     *
     * Returns null for: null index, index < 1, or index beyond array length.
     *
     * @template T
     *
     * @param list<T> $items
     *
     * @return T|null
     */
    public static function at(?int $oneBasedIndex, array $items): mixed
    {
        if ($oneBasedIndex === null || $oneBasedIndex < 1) {
            return null;
        }

        return $items[$oneBasedIndex - 1] ?? null;
    }
}
