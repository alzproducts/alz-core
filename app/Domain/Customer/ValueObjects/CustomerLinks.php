<?php

declare(strict_types=1);

namespace App\Domain\Customer\ValueObjects;

final readonly class CustomerLinks
{
    public function __construct(
        public string $editWebsiteUrl,
    ) {}
}
