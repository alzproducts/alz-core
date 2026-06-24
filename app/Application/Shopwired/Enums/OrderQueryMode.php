<?php

declare(strict_types=1);

namespace App\Application\Shopwired\Enums;

/**
 * Filtering mode for a ShopWired order date-range read.
 *
 * Filtered = business view: test-email exclusion + reference deduplication
 *            (reads the orders_deduplicated view).
 * Raw      = unfiltered database contents (test orders and duplicate
 *            references included); for auditing/debugging only.
 */
enum OrderQueryMode
{
    case Filtered;
    case Raw;
}
