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

    /**
     * Serialize to array for JSONB storage.
     *
     * @return array{order_note_id: string, note_date: string, internal: bool, note: string, created_by: string, note_type_id: int|null}
     */
    public function toArray(): array
    {
        return [
            'order_note_id' => $this->orderNoteId->value,
            'note_date' => $this->noteDate->format('c'),
            'internal' => $this->internal,
            'note' => $this->note,
            'created_by' => $this->createdBy,
            'note_type_id' => $this->noteTypeId,
        ];
    }
}
