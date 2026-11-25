<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Enums;

/**
 * How a built order's expected total compares with its ShopWired total.
 */
enum DryRunVerdict: string
{
    case Match = 'match';
    case Warning = 'warning';
}
