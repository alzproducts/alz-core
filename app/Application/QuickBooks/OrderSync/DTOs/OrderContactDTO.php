<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

/**
 * A billing or delivery contact as the order carries it; an absent field is an empty string.
 */
final readonly class OrderContactDTO
{
    public function __construct(
        public string $name,
        public string $company,
        public string $line1,
        public string $line2,
        public string $line3,
        public string $city,
        public string $postcode,
        public string $country,
    ) {}
}
