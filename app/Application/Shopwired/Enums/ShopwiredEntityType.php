<?php

declare(strict_types=1);

namespace App\Application\Shopwired\Enums;

enum ShopwiredEntityType
{
    case Order;
    case Product;
    case Customer;
    case Brand;
    case Category;
}
