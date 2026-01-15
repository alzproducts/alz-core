<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Enums;

/**
 * Sort options for ShopWired Order API.
 *
 * Used with OrderQueryParams::withSort() to control order ordering.
 * For bulk sync operations, use DateDesc (newest first) so recent orders
 * are prioritized if sync fails mid-way.
 *
 * @see https://shopwired.readme.io/reference/listorders
 */
enum OrderSort: string
{
    case Date = 'date';
    case DateDesc = 'date_desc';
}
