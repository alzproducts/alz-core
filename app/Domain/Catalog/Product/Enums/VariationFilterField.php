<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Enums;

enum VariationFilterField: string
{
    case IsActive = 'is_active';
    case CategoryId = 'category_id';
    case IsOnSale = 'is_on_sale';
    case HasFreeDelivery = 'has_free_delivery';
}
