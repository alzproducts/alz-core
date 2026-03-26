<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Brand\ValueObjects;

use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;

/**
 * Read-only API projection of a brand.
 *
 * Separates the API view from the core write model (Brand):
 * - Domain-typed IntId instead of primitive int
 * - Conditional includes (null = not loaded)
 *
 * Constructed by BrandModel::toViewDomain().
 */
final readonly class BrandView
{
    /**
     * @param IntId $id ShopWired brand ID (external identifier)
     * @param string $title Brand title
     * @param string $slug URL slug
     * @param string $url Full brand URL
     * @param bool $active Whether brand is active
     * @param bool $featured Whether brand is featured
     * @param int $sortOrder Display ordering
     * @param ?string $metaTitle SEO title
     * @param ?string $metaDescription SEO description
     * @param ?string $metaKeywords SEO keywords
     * @param ?BrandImage $image Brand image
     * @param DateTimeImmutable $createdAt ShopWired creation timestamp
     * @param ?string $description Brand description (null = not loaded)
     * @param ?array<string, mixed> $customFields Custom field key-value pairs (null = not loaded)
     */
    public function __construct(
        public IntId $id,
        public string $title,
        public string $slug,
        public string $url,
        public bool $active,
        public bool $featured,
        public int $sortOrder,
        public ?string $metaTitle,
        public ?string $metaDescription,
        public ?string $metaKeywords,
        public ?BrandImage $image,
        public DateTimeImmutable $createdAt,
        public ?string $description = null,
        public ?array $customFields = null,
    ) {}
}
