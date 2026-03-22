<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Brand\Enums;

/**
 * Fields on a ShopWired brand that can be updated via simple PUT.
 */
enum BrandUpdatableField
{
    case Title;
}
