<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Responses\PurchaseOrder;

use App\Domain\Linnworks\ValueObjects\PurchaseOrderExtendedProperty;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Contracts\DomainConvertibleInterface;
use App\Infrastructure\Linnworks\Support\PascalCaseMapper;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Linnworks PurchaseOrder extended property API response DTO.
 *
 * Maps the Get_PurchaseOrderExtendedProperty response items.
 *
 * @template-pattern Infrastructure Response DTO
 */
#[MapInputName(PascalCaseMapper::class)]
final class PurchaseOrderExtendedPropertyResponse extends Data implements DomainConvertibleInterface
{
    public function __construct(
        #[MapInputName('RowId')]
        public readonly ?int $rowId,
        #[MapInputName('PurchaseID')]
        public readonly ?string $purchaseId,
        public readonly ?string $addedDateTime,
        #[MapInputName('UserName')]
        public readonly ?string $username,
        public readonly string $propertyName,
        public readonly string $propertyValue,
    ) {}

    public function toDomain(): PurchaseOrderExtendedProperty
    {
        return new PurchaseOrderExtendedProperty(
            rowId: $this->rowId,
            purchaseId: $this->purchaseId !== null ? Guid::fromTrusted($this->purchaseId) : null,
            addedDateTime: $this->addedDateTime,
            username: $this->username,
            propertyName: $this->propertyName,
            propertyValue: $this->propertyValue,
        );
    }
}
