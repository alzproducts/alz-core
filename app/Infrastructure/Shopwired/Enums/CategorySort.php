<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Enums;

/**
 * Sort options for ShopWired Categories endpoint.
 *
 * @see https://shopwired.readme.io/docs/categories
 */
enum CategorySort: string
{
    case CreatedAsc = 'created_asc';
    case CreatedDesc = 'created_desc';
    case Title = 'title';
    case TitleDesc = 'title_desc';
}
