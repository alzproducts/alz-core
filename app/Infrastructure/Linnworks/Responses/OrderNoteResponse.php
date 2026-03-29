<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Responses;

use App\Domain\Linnworks\ValueObjects\LinnworksOrderNote;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Contracts\DomainConvertibleInterface;
use App\Infrastructure\Linnworks\Support\PascalCaseMapper;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * DTO for order note from the v2 GetOrders endpoint.
 *
 * @template-pattern Infrastructure Response DTO
 */
#[MapInputName(PascalCaseMapper::class)]
final class OrderNoteResponse extends Data implements DomainConvertibleInterface
{
    public function __construct(
        public readonly string $orderNoteId,
        public readonly string $noteDate,
        public readonly bool $internal,
        public readonly string $note,
        public readonly string $createdBy,
        public readonly ?int $noteTypeId,
    ) {}

    public function toDomain(): LinnworksOrderNote
    {
        return new LinnworksOrderNote(
            orderNoteId: new Guid($this->orderNoteId),
            noteDate: CarbonImmutable::parse($this->noteDate)->toDateTimeImmutable(),
            internal: $this->internal,
            note: $this->note,
            createdBy: $this->createdBy,
            noteTypeId: $this->noteTypeId,
        );
    }
}
