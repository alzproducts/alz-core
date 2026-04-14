<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Brand\ValueObjects;

final readonly class BrandLinks
{
    public function __construct(
        public string $publicUrl,
        public string $editWebsiteUrl,
    ) {}
}
