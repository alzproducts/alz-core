<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Filters\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * Filter group definition from ShopWired.
 *
 * Represents the metadata for a filter group used in faceted navigation.
 * Filter groups define categories like "Size", "Colour", "VAT Relief Eligible".
 * Products have filter values assigned per group.
 *
 * Key field: `optionNo` is used as the key in product filter data
 * (e.g., `{"1": ["Small", "Large"]}` where 1 is the optionNo).
 *
 * @see https://shopwired.readme.io/reference/listfiltergroups (undocumented endpoint)
 */
final readonly class FilterGroupDefinition
{
    /**
     * @param int $id ShopWired filter group ID (external_id)
     * @param string $title Human-readable group name (e.g., "Size", "Colour")
     * @param int $optionNo Unique option number used as key in product filters
     * @param int $sortOrder Display ordering (lower = first)
     */
    public function __construct(
        public int $id,
        public string $title,
        public int $optionNo,
        public int $sortOrder,
    ) {
        Assert::greaterThan($id, 0, 'Filter group ID must be positive');
        Assert::notEmpty($title, 'Filter group title cannot be empty');
        Assert::greaterThan($optionNo, 0, 'Filter group optionNo must be positive');
    }
}
