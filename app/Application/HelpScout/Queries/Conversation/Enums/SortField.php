<?php

declare(strict_types=1);

namespace App\Application\HelpScout\Queries\Conversation\Enums;

/**
 * HelpScout API sort field options.
 *
 * @see https://developer.helpscout.com/mailbox-api/endpoints/conversations/list/
 */
enum SortField: string
{
    case WaitingSince = 'waitingSince';
    case CreatedAt = 'createdAt';
    case ModifiedAt = 'modifiedAt';
    case Number = 'number';
}
