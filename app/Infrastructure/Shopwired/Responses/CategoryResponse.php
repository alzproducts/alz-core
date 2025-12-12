<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use App\Domain\Catalog\ValueObjects\Category as DomainCategory;
use App\Infrastructure\Contracts\DomainConvertible;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * ShopWired API Response: Category
 *
 * Infrastructure DTO for parsing category API responses.
 * Handles snake_case → camelCase mapping automatically.
 *
 * @see https://shopwired.readme.io/docs/categories
 */
#[MapInputName(SnakeCaseMapper::class)]
final class CategoryResponse extends Data implements DomainConvertible
{
    /**
     * @param list<CategoryResponse> $parents Parent categories (closest first, root last)
     * @param array<string, mixed> $customFields Custom field key-value pairs (requires custom_fields embed)
     */
    public function __construct(
        public readonly int $id,
        public readonly string $createdAt,
        public readonly string $title,
        public readonly ?string $description,
        public readonly ?string $description2,
        public readonly string $slug,
        public readonly string $url,
        public readonly bool $active,
        public readonly bool $featured,
        public readonly bool $tradeOnly,
        public readonly int $sortOrder,
        public readonly ?string $metaTitle,
        public readonly ?string $metaDescription,
        public readonly ?string $metaKeywords,
        public readonly bool $metaNoIndex,
        public readonly ?CategoryImageResponse $image = null,
        #[DataCollectionOf(CategoryResponse::class)]
        public readonly array $parents = [],
        public readonly array $customFields = [],
    ) {}

    /**
     * Convert to Domain Value Object.
     */
    public function toDomain(): DomainCategory
    {
        return new DomainCategory(
            title: $this->title,
            description: $this->description,
            description2: $this->description2,
            slug: $this->slug,
            url: $this->url,
            active: $this->active,
            featured: $this->featured,
            tradeOnly: $this->tradeOnly,
            sortOrder: $this->sortOrder,
            metaTitle: $this->metaTitle,
            metaDescription: $this->metaDescription,
            metaKeywords: $this->metaKeywords,
            metaNoIndex: $this->metaNoIndex,
            image: $this->image?->toDomain(),
            parents: \array_map(
                static fn(CategoryResponse $parent): DomainCategory => $parent->toDomain(),
                $this->parents,
            ),
            customFields: $this->customFields,
        );
    }
}
