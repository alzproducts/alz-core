<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Category\Enums;

/**
 * Fields on a ShopWired category that can be updated via simple PUT.
 */
enum CategoryUpdatableField
{
    case Title;
}
