<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use App\Domain\Catalog\Filters\ValueObjects\FilterGroupDefinition;
use App\Infrastructure\Contracts\DomainConvertibleInterface;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * ShopWired API Response: Filter Group Definition.
 *
 * Infrastructure DTO for parsing filter group definition API responses.
 * Handles snake_case → camelCase mapping automatically.
 *
 * Note: This endpoint is undocumented in official ShopWired API docs.
 */
#[MapInputName(SnakeCaseMapper::class)]
final class FilterGroupDefinitionResponse extends Data implements DomainConvertibleInterface
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        #[MapInputName('option')]
        public readonly int $optionNo,
        public readonly int $sortOrder,
    ) {}

    /**
     * Convert to Domain Value Object.
     */
    public function toDomain(): FilterGroupDefinition
    {
        return new FilterGroupDefinition(
            id: $this->id,
            title: $this->title,
            optionNo: $this->optionNo,
            sortOrder: $this->sortOrder,
        );
    }
}
