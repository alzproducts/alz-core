<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Enums;

use App\Application\QuickBooks\Mappings\DTOs\MappingDTO;

/**
 * Read from the stored row alone; QuickBooks-backed problems are MappingFindingKind's.
 */
enum MappingState: string
{
    case Resolved = 'resolved';
    case Unresolved = 'unresolved';
    case OtherRealm = 'other_realm';

    public static function of(MappingDTO $row, string $realmId): self
    {
        return match (true) {
            $row->refId === null => self::Unresolved,
            $row->realmId !== $realmId => self::OtherRealm,
            default => self::Resolved,
        };
    }
}
