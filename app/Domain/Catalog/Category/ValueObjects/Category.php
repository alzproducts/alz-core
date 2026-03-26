<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Category\ValueObjects;

use DateTimeImmutable;
use Webmozart\Assert\Assert;

/**
 * Category Value Object.
 *
 * Represents a product category with its metadata.
 * Used for category listing and business logic operations.
 */
final readonly class Category
{
    /**
     * @param list<int> $parentIds Parent category external IDs (closest first, root last)
     * @param array<string, mixed> $customFields Custom field key-value pairs
     */
    public function __construct(
        public int $id,
        public DateTimeImmutable $createdAt,
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
        public array $parentIds = [],
        public array $customFields = [],
    ) {
        Assert::greaterThan($id, 0);
    }
}
