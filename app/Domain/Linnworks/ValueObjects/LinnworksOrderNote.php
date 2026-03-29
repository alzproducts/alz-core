<?php

declare(strict_types=1);

namespace App\Domain\Linnworks\ValueObjects;

use App\Domain\ValueObjects\Guid;
use DateTimeImmutable;

/**
 * Linnworks order note value object.
 *
 * Notes are stored as JSONB on the orders table rather than in a
 * separate table — they're a small collection with no independent
 * queryability needs.
 *
 * @template-pattern Domain Value Object
 */
final readonly class LinnworksOrderNote
{
    public function __construct(
        public Guid $orderNoteId,
        public DateTimeImmutable $noteDate,
        public bool $internal,
        public string $note,
        public string $createdBy,
        public ?int $noteTypeId,
    ) {}
}
