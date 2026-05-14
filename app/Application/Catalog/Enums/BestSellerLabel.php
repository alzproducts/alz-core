<?php

declare(strict_types=1);

namespace App\Application\Catalog\Enums;

enum BestSellerLabel: string
{
    case BestSellers = 'Best Sellers';

    public const string FIELD = 'custom_label_4';
}
