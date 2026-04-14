<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

final readonly class ProductLinks
{
    public function __construct(
        public string $publicUrl,
        public string $editWebsiteUrl,
    ) {}
}
