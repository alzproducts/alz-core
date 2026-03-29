<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Responses;

use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Linnworks\ValueObjects\LinnworksOrderExtendedProperty;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Contracts\DomainConvertibleInterface;
use App\Infrastructure\Linnworks\Support\LinnworksDateParser;
use App\Infrastructure\Linnworks\Support\PascalCaseMapper;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * DTO for order extended property from the v2 GetOrders endpoint.
 *
 * @template-pattern Infrastructure Response DTO
 */
#[MapInputName(PascalCaseMapper::class)]
final class OrderExtendedPropertyResponse extends Data implements DomainConvertibleInterface
{
    public function __construct(
        public readonly string $rowId,
        /** @note v2 API uses "Name" (v1 used the typo "ProperyName") */
        #[MapInputName('Name')]
        public readonly string $properyName,
        #[MapInputName('Value')]
        public readonly string $propertyValue,
        #[MapInputName('Type')]
        public readonly string $propertyType,
        public readonly ?string $createDate,
        public readonly ?string $lastUpdate,
        public readonly string $updatedBy,
    ) {}

    /**
     * @throws InvalidApiResponseException
     */
    public function toDomain(): LinnworksOrderExtendedProperty
    {
        return new LinnworksOrderExtendedProperty(
            rowId: new Guid($this->rowId),
            name: $this->properyName,
            value: $this->propertyValue,
            type: $this->propertyType,
            createDate: LinnworksDateParser::parse($this->createDate),
            lastUpdate: LinnworksDateParser::parse($this->lastUpdate),
            updatedBy: $this->updatedBy,
        );
    }
}
