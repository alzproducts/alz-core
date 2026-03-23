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
 * @template-pattern Infrastructure Response DTO
 */
#[MapInputName(PascalCaseMapper::class)]
final class PurchaseOrderNoteResponse extends Data implements DomainConvertibleInterface
{
    public function __construct(
        #[MapInputName('PurchaseOrderId')]
        public readonly string $pkPurchaseId,
        public readonly string $note,
        public readonly ?string $dateTime,
        public readonly ?string $forename,
        public readonly ?string $surname,
    ) {}

    /**
     * @throws InvalidApiResponseException When date parsing fails
     */
    public function toDomain(): PurchaseOrderNote
    {
        return new PurchaseOrderNote(
            pkPurchaseId: Guid::fromTrusted($this->pkPurchaseId),
            note: $this->note,
            dateTime: LinnworksDateParser::parse($this->dateTime),
            forename: $this->forename,
            surname: $this->surname,
        );
    }
}
