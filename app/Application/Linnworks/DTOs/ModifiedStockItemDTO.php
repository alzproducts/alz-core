<?php

declare(strict_types=1);

namespace App\Application\Linnworks\DTOs;

use App\Domain\ValueObjects\Guid;
use DateTimeImmutable;

/**
 * A stock item modified since the last cursor check.
 *
 * Returned by the ModifiedStockItemQuery, ordered by ModifiedDate ASC.
 * The last element holds the newest timestamp for cursor advancement.
 */
final readonly class ModifiedStockItemDTO
{
    public function __construct(
        public Guid $stockItemId,
        public DateTimeImmutable $modifiedDate,
    ) {}
}
