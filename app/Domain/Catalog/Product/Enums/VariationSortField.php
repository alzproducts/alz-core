<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Enums;

enum VariationSortField: string
{
    case Price = 'price';
    case EffectivePrice = 'effective_price';
    case Stock = 'stock';
    case ProfitMargin = 'profit_margin';
    case CreatedAt = 'created_at';
    case UpdatedAt = 'updated_at';
    case Popularity = 'popularity';
}
