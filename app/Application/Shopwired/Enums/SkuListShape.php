<?php

declare(strict_types=1);

namespace App\Application\Shopwired\Enums;

enum SkuListShape: string
{
    case Flat = 'flat';
    case GroupedByProduct = 'grouped_by_product';
}
