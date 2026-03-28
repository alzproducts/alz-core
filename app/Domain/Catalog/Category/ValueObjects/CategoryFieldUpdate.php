<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Category\ValueObjects;

use App\Domain\Catalog\Category\Enums\CategoryUpdatableField;

/**
 * Represents a single field update for a ShopWired category.
 *
 * Use static factory methods for type-safe construction.
 * The API field name mapping lives in Infrastructure.
 */
final readonly class CategoryFieldUpdate
{
    private function __construct(
        public CategoryUpdatableField $field,
        public string $value,
    ) {}

    public static function title(string $title): self
    {
        return new self(CategoryUpdatableField::Title, $title);
    }

    public static function description(string $description): self
    {
        return new self(CategoryUpdatableField::Description, $description);
    }

    public static function metaTitle(string $metaTitle): self
    {
        return new self(CategoryUpdatableField::MetaTitle, $metaTitle);
    }

    public static function metaDescription(string $metaDescription): self
    {
        return new self(CategoryUpdatableField::MetaDescription, $metaDescription);
    }
}
