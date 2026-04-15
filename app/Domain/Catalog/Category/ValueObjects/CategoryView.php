<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Category\ValueObjects;

use App\Domain\Catalog\CustomFields\ValueObjects\AbstractCustomFieldValue;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;

/**
 * Read-only API projection of a category.
 *
 * Separates the API view from the core write model (Category):
 * - Domain-typed IntId instead of primitive int
 * - Conditional includes (null = not loaded)
 *
 * Constructed by CategoryModel::toViewDomain().
 */
final readonly class CategoryView
{
    /**
     * @param IntId $id ShopWired category ID (external identifier)
     * @param string $title Category title
     * @param string $slug URL slug
     * @param CategoryLinks $links Public and admin edit URLs
     * @param bool $active Whether category is active
     * @param bool $featured Whether category is featured
     * @param int $sortOrder Display ordering
     * @param ?string $metaTitle SEO title
     * @param ?string $metaDescription SEO description
     * @param ?CategoryImage $image Category image
     * @param DateTimeImmutable $createdAt ShopWired creation timestamp
     * @param bool $isMainCategory Whether this category is a main category
     * @param ?string $description Primary description (null = not loaded)
     * @param ?string $description2 Secondary description (null = not loaded)
     * @param ?list<IntId> $parentIds Parent category IDs (null = not loaded)
     * @param ?list<AbstractCustomFieldValue> $customFields Typed custom field values (null = not loaded)
     */
    public function __construct(
        public IntId $id,
        public string $title,
        public string $slug,
        public CategoryLinks $links,
        public bool $active,
        public bool $featured,
        public int $sortOrder,
        public ?string $metaTitle,
        public ?string $metaDescription,
        public ?CategoryImage $image,
        public DateTimeImmutable $createdAt,
        public bool $isMainCategory = false,
        public ?string $description = null,
        public ?string $description2 = null,
        public ?array $parentIds = null,
        public ?array $customFields = null,
    ) {}
}
