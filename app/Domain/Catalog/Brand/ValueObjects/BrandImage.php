<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Brand\ValueObjects;

/**
 * Brand Image Value Object.
 *
 * Represents an image associated with a product brand.
 */
final readonly class BrandImage
{
    public function __construct(
        public string $url,
    ) {}
}
