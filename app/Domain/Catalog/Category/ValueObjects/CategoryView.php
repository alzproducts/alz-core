<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Category\ValueObjects;

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
     * @param string $url Full category URL
     * @param bool $active Whether category is active
     * @param bool $featured Whether category is featured
     * @param bool $tradeOnly Whether category is trade-only
     * @param int $sortOrder Display ordering
     * @param ?string $metaTitle SEO title
     * @param ?string $metaDescription SEO description
     * @param ?string $metaKeywords SEO keywords
     * @param bool $metaNoIndex Whether to noindex
     * @param ?CategoryImage $image Category image
     * @param DateTimeImmutable $createdAt ShopWired creation timestamp
     * @param ?string $description Primary description (null = not loaded)
     * @param ?string $description2 Secondary description (null = not loaded)
     * @param ?list<IntId> $parentIds Parent category IDs (null = not loaded)
     * @param ?array<string, mixed> $customFields Custom field key-value pairs (null = not loaded)
     */
    public function __construct(
        public IntId $id,
        public string $title,
        public string $slug,
        public string $url,
        public bool $active,
        public bool $featured,
        public bool $tradeOnly,
        public int $sortOrder,
        public ?string $metaTitle,
        public ?string $metaDescription,
        public ?string $metaKeywords,
        public bool $metaNoIndex,
        public ?CategoryImage $image,
        public DateTimeImmutable $createdAt,
        public ?string $description = null,
        public ?string $description2 = null,
        public ?array $parentIds = null,
        public ?array $customFields = null,
    ) {}
}
