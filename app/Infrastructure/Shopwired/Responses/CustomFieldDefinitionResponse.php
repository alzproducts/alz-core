<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\Exceptions\InvalidApiResponseException;
use App\Infrastructure\Contracts\DomainConvertibleInterface;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * ShopWired API Response: Custom Field Definition.
 *
 * Infrastructure DTO for parsing custom field definition API responses.
 * Handles snake_case → camelCase mapping automatically.
 *
 * @see https://shopwired.readme.io/reference/listcustomfields
 */
#[MapInputName(SnakeCaseMapper::class)]
final class CustomFieldDefinitionResponse extends Data implements DomainConvertibleInterface
{
    /**
     * @param list<string>|null $allowedValues Valid values for choice/list types
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $type,
        public readonly ?string $label,
        public readonly string $itemType,
        public readonly ?int $sortOrder,
        public readonly ?array $allowedValues,
    ) {}

    /**
     * Convert to Domain Value Object.
     *
     * @throws InvalidApiResponseException When type or itemType is unknown
     */
    public function toDomain(): CustomFieldDefinition
    {
        $type = CustomFieldType::tryFrom($this->type);
        if ($type === null) {
            throw new InvalidApiResponseException(
                serviceName: 'Shopwired',
                message: "Unknown custom field type '{$this->type}' for field '{$this->name}'",
            );
        }

        $itemType = CustomFieldItemType::tryFrom($this->itemType);
        if ($itemType === null) {
            throw new InvalidApiResponseException(
                serviceName: 'Shopwired',
                message: "Unknown custom field item type '{$this->itemType}' for field '{$this->name}'",
            );
        }

        return new CustomFieldDefinition(
            id: $this->id,
            name: $this->name,
            type: $type,
            label: $this->label,
            itemType: $itemType,
            sortOrder: $this->sortOrder,
            allowedValues: $this->allowedValues,
        );
    }
}
