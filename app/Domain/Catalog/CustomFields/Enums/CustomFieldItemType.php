<?php

declare(strict_types=1);

namespace App\Domain\Catalog\CustomFields\Enums;

/**
 * Entity types that can have custom fields attached.
 *
 * Custom fields in ShopWired are scoped to specific entity types,
 * allowing different fields for products vs customers vs orders, etc.
 *
 * @see https://shopwired.readme.io/reference/listcustomfields
 */
enum CustomFieldItemType: string
{
    case Product = 'product';
    case Category = 'category';
    case Customer = 'customer';
    case Brand = 'brand';
    case Order = 'order';
    case Page = 'page';
    case BlogPost = 'blog_post';
}
