<?php

declare(strict_types=1);

namespace App\Domain\Shared\Pagination\Enums;

enum SortDirection: string
{
    case Asc = 'asc';
    case Desc = 'desc';
}
