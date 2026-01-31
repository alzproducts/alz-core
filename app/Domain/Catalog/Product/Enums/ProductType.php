<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Enums;

/**
 * Distinguishes between main products and variations.
 *
 * Use when the caller knows the entity type upfront, enabling
 * targeted repository lookups instead of searching both tables.
 */
enum ProductType: string
{
    case Main = 'main';
    case Variation = 'variation';
}
