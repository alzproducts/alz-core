<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Brand\ValueObjects;

use App\Domain\Catalog\Brand\Enums\BrandUpdatableField;

/**
 * Represents a single field update for a ShopWired brand.
 *
 * Use static factory methods for type-safe construction.
 * The API field name mapping lives in Infrastructure.
 */
final readonly class BrandFieldUpdate
{
    private function __construct(
        public BrandUpdatableField $field,
        public string $value,
    ) {}

    public static function title(string $title): self
    {
        return new self(BrandUpdatableField::Title, $title);
    }
}
