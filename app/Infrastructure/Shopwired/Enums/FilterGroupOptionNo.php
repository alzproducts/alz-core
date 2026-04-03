<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Enums;

/**
 * Maps known ShopWired filter groups to their optionNo values.
 *
 * These values are hardcoded in the ShopWired system and protected
 * by integration guard tests (CustomerRatingFilterGroupGuardTest).
 */
enum FilterGroupOptionNo: int
{
    case CustomerRating = 15;
}
