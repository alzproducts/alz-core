<?php

declare(strict_types=1);

namespace App\Domain\Catalog\ValueObjects;

/**
 * Category Image Value Object.
 *
 * Represents an image associated with a product category.
 */
final readonly class CategoryImage
{
    public function __construct(
        public string $url,
    ) {}
}
