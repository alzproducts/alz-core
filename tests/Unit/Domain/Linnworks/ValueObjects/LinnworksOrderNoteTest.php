<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Linnworks\ValueObjects;

use App\Domain\Linnworks\ValueObjects\LinnworksOrderNote;
use App\Domain\ValueObjects\Guid;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LinnworksOrderNote::class)]
final class LinnworksOrderNoteTest extends TestCase
{
    #[Test]
    public function constructor_stores_all_properties(): void
    {
        $guid = new Guid('11111111-2222-3333-4444-555555555555');
        $date = new DateTimeImmutable('2026-03-23T10:00:00+00:00');

        $note = new LinnworksOrderNote(
            orderNoteId: $guid,
            noteDate: $date,
            internal: true,
            note: 'Customer requested gift wrap',
            createdBy: 'agent@example.com',
            noteTypeId: 42,
        );

        self::assertSame($guid, $note->orderNoteId);
        self::assertSame($date, $note->noteDate);
        self::assertTrue($note->internal);
        self::assertSame('Customer requested gift wrap', $note->note);
        self::assertSame('agent@example.com', $note->createdBy);
        self::assertSame(42, $note->noteTypeId);
    }

    #[Test]
    public function constructor_accepts_null_note_type_id(): void
    {
        $note = $this->makeNote(noteTypeId: null);

        self::assertNull($note->noteTypeId);
    }

    #[Test]
    public function constructor_accepts_false_internal_flag(): void
    {
        $note = $this->makeNote(internal: false);

        self::assertFalse($note->internal);
    }

    #[Test]
    public function to_array_emits_full_snake_case_payload_with_iso8601_date(): void
    {
        $note = new LinnworksOrderNote(
            orderNoteId: new Guid('11111111-2222-3333-4444-555555555555'),
            noteDate: new DateTimeImmutable('2026-03-23T10:30:45+00:00'),
            internal: true,
            note: 'Restocking note',
            createdBy: 'system@example.com',
            noteTypeId: 7,
        );

        self::assertSame([
            'order_note_id' => '11111111-2222-3333-4444-555555555555',
            'note_date' => '2026-03-23T10:30:45+00:00',
            'internal' => true,
            'note' => 'Restocking note',
            'created_by' => 'system@example.com',
            'note_type_id' => 7,
        ], $note->toArray());
    }

    #[Test]
    public function to_array_preserves_null_note_type_id(): void
    {
        $note = $this->makeNote(noteTypeId: null);

        self::assertNull($note->toArray()['note_type_id']);
    }

    #[Test]
    public function to_array_preserves_false_internal_flag(): void
    {
        $note = $this->makeNote(internal: false);

        self::assertFalse($note->toArray()['internal']);
    }

    private function makeNote(
        ?Guid $orderNoteId = null,
        ?DateTimeImmutable $noteDate = null,
        bool $internal = true,
        string $note = 'A note',
        string $createdBy = 'agent',
        ?int $noteTypeId = 1,
    ): LinnworksOrderNote {
        return new LinnworksOrderNote(
            orderNoteId: $orderNoteId ?? new Guid('11111111-2222-3333-4444-555555555555'),
            noteDate: $noteDate ?? new DateTimeImmutable('2026-03-23T00:00:00+00:00'),
            internal: $internal,
            note: $note,
            createdBy: $createdBy,
            noteTypeId: $noteTypeId,
        );
    }
}
