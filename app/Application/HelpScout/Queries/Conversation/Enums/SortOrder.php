<?php

declare(strict_types=1);

namespace App\Application\HelpScout\Queries\Conversation\Enums;

/**
 * Sort order for HelpScout API queries.
 */
enum SortOrder: string
{
    case Asc = 'asc';
    case Desc = 'desc';
}
