<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Responses;

use App\Domain\Inventory\ValueObjects\StockItemExtendedProperty;
use App\Infrastructure\Contracts\DomainConvertibleInterface;
use App\Infrastructure\Linnworks\Support\PascalCaseMapper;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Linnworks Extended Property API response DTO.
 *
 * Maps fields from GetStockItemsFull endpoint's ExtendedProperties array.
 * Linnworks uses PascalCase: pkRowId, ProperyName (note: typo in API!), PropertyValue, PropertyType.
 *
 * @template-pattern Infrastructure Response DTO
 */
#[MapInputName(PascalCaseMapper::class)]
final class ExtendedPropertyResponse extends Data implements DomainConvertibleInterface
{
    public function __construct(
        public readonly string $pkRowId,
        /** @note Linnworks API has a typo: "ProperyName" instead of "PropertyName" */
        public readonly string $properyName,
        public readonly string $propertyValue,
        public readonly string $propertyType,
    ) {}

    public function toDomain(): StockItemExtendedProperty
    {
        return new StockItemExtendedProperty(
            rowId: $this->pkRowId,
            name: $this->properyName,
            value: $this->propertyValue,
            type: $this->propertyType,
        );
    }
}
