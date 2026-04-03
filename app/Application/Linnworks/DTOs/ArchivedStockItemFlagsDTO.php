<?php

declare(strict_types=1);

namespace App\Application\Linnworks\DTOs;

use App\Domain\ValueObjects\Guid;

/**
 * Archived and logically-deleted stock item IDs from Linnworks.
 *
 * Returned by getArchivedStockItemIds() — holds the two flag categories
 * separately so the repository can run targeted bulk updates per flag.
 */
final readonly class ArchivedStockItemFlagsDTO
{
    /**
     * @param list<Guid> $archivedIds  Linnworks GUIDs of archived stock items
     * @param list<Guid> $deletedIds   Linnworks GUIDs of logically-deleted stock items
     */
    public function __construct(
        public array $archivedIds,
        public array $deletedIds,
    ) {}
}
