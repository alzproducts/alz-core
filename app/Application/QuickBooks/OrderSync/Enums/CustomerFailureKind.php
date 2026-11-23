<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Enums;

enum CustomerFailureKind: string
{
    /** The display name belongs to an inactive customer, which QBO will not post against. */
    case Inactive = 'inactive';

    /** QBO refused the create as a duplicate name, and no customer holds it (another name list does). */
    case NameClash = 'name_clash';

    /** QBO refused the create with any other Fault. */
    case Faulted = 'faulted';
}
