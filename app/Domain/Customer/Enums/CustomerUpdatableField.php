<?php

declare(strict_types=1);

namespace App\Domain\Customer\Enums;

/**
 * Fields on a ShopWired customer that can be updated via simple PUT.
 */
enum CustomerUpdatableField
{
    case FirstName;
}
