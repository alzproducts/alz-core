<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Category\ValueObjects;

final readonly class CategoryLinks
{
    public function __construct(
        public string $publicUrl,
        public string $editWebsiteUrl,
    ) {}
}
