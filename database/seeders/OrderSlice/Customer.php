<?php

declare(strict_types=1);

namespace Database\Seeders\OrderSlice;

/**
 * A seeded order's customer; used for both the billing and the delivery address.
 */
final readonly class Customer
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public string $addressLine1,
        public string $city,
        public string $postcode,
        public string $country = 'United Kingdom',
        public ?string $company = null,
    ) {}
}
