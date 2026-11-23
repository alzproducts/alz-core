<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Enums;

enum MappingFindingKind: string
{
    case Unresolved = 'unresolved';
    case OtherRealm = 'other realm';
    case NameDrift = 'name drift';
    case Inactive = 'inactive';
    case NotFound = 'not found';
}
