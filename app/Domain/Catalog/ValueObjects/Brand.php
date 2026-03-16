<?php

declare(strict_types=1);

namespace App\Domain\Catalog\ValueObjects;

use DateTimeImmutable;
use Webmozart\Assert\Assert;

/**
 * Brand Value Object.
 *
 * Represents a product brand with its metadata.
 * Used for brand listing and business logic operations.
 */
final readonly class Brand
{
    /**
     * @param array<string, mixed> $customFields Custom field key-value pairs
     */
    public function __construct(
        public int $id,
        public DateTimeImmutable $createdAt,
        public string $title,
        public ?string $description,
        public string $slug,
        public string $url,
        public bool $active,
        public bool $featured,
        public int $sortOrder,
        public ?string $metaTitle,
        public ?string $metaKeywords,
        public ?string $metaDescription,
        public ?BrandImage $image = null,
        public array $customFields = [],
    ) {
        Assert::greaterThan($id, 0);
    }
}
