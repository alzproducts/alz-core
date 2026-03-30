<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Responses\PurchaseOrder;

use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderNote;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Contracts\DomainConvertibleInterface;
use App\Infrastructure\Linnworks\Support\LinnworksDateParser;
use App\Infrastructure\Linnworks\Support\PascalCaseMapper;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Linnworks PurchaseOrder note API response DTO.
 *
 * Maps the Get_PurchaseOrderNote response items.
 *
 * Note: The API returns `NoteDateTime` (not `DateTime`) and `UserName`
 * (not separate Forename/Surname). Field names verified against real API.
 *
 * @template-pattern Infrastructure Response DTO
 */
#[MapInputName(PascalCaseMapper::class)]
final class PurchaseOrderNoteResponse extends Data implements DomainConvertibleInterface
{
    public function __construct(
        #[MapInputName('pkPurchaseOrderNoteId')]
        public readonly string $pkPurchaseOrderNoteId,
        public readonly string $note,
        #[MapInputName('NoteDateTime')]
        public readonly ?string $noteDateTime,
        #[MapInputName('UserName')]
        public readonly ?string $userName,
    ) {}

    /**
     * @throws InvalidApiResponseException When date parsing fails
     */
    public function toDomain(): PurchaseOrderNote
    {
        return new PurchaseOrderNote(
            pkPurchaseOrderNoteId: Guid::fromTrusted($this->pkPurchaseOrderNoteId),
            note: $this->note,
            dateTime: LinnworksDateParser::parse($this->noteDateTime),
            userName: $this->userName,
        );
    }
}
