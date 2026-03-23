<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\Product\Enums\ProductUpdatableField;

/**
 * Represents a single field update for a ShopWired product.
 *
 * Use static factory methods for type-safe construction.
 * The API field name mapping lives in Infrastructure.
 */
final readonly class ProductFieldUpdate
{
    /**
     * @param string|int|float|bool|array<mixed> $value
     */
    private function __construct(
        public ProductUpdatableField $field,
        public string|int|float|bool|array $value,
    ) {}

    public static function title(string $title): self
    {
        return new self(ProductUpdatableField::Title, $title);
    }

    public static function description(string $description): self
    {
        return new self(ProductUpdatableField::Description, $description);
    }

    public static function metaTitle(string $metaTitle): self
    {
        return new self(ProductUpdatableField::MetaTitle, $metaTitle);
    }

    public static function metaDescription(string $metaDescription): self
    {
        return new self(ProductUpdatableField::MetaDescription, $metaDescription);
    }

    /**
     * @param list<int> $categoryIds
     */
    public static function categories(array $categoryIds): self
    {
        return new self(ProductUpdatableField::Categories, $categoryIds);
    }

    public static function sortOrder(int $sortOrder): self
    {
        return new self(ProductUpdatableField::SortOrder, $sortOrder);
    }
}
