<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Enums;

/**
 * Sort options for ShopWired Customer API.
 *
 * Used with CustomerQueryParams::withSort() to control customer ordering.
 * For bulk sync operations, use CreatedAsc for deterministic pagination.
 *
 * @see https://shopwired.readme.io/reference/listcustomers
 */
enum CustomerSort: string
{
    case Created = 'created';
    case CreatedAsc = 'created_asc';
    case CreatedDesc = 'created_desc';
    case Name = 'name';
    case NameDesc = 'name_desc';
    case Company = 'company';
    case CompanyDesc = 'company_desc';
}
