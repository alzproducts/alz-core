<?php

declare(strict_types=1);

namespace App\Domain\Catalog\ValueObjects;

/**
 * Category Value Object.
 *
 * Represents a product category with its metadata.
 * Used for category listing and business logic operations.
 */
final readonly class Category
{
    /**
     * @param list<Category> $parents Parent categories (closest first, root last)
     */
    public function __construct(
        public string $title,
        public ?string $description,
        public ?string $description2,
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
        public ?CategoryImage $image = null,
        public array $parents = [],
    ) {}
}
