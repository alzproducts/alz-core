<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Enums;

/**
 * Fields on a ShopWired product that can be updated via simple PUT.
 *
 * Excludes fields requiring fetch-merge-PUT (customFields, filters)
 * or dedicated batch endpoints (prices, stock).
 */
enum ProductUpdatableField
{
    case Title;
    case Description;
    case MetaTitle;
    case MetaDescription;
    case Categories;
    case SortOrder;
}
