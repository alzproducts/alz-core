<?php

declare(strict_types=1);

namespace App\Domain\QuickBooks\OrderSync\ValueObjects;

final readonly class StructuredAddress
{
    private function __construct(
        public string $line1,
        public string $line2,
        public string $city,
        public string $postcode,
        public string $country,
    ) {}

    public static function fromContact(OrderContact $contact): self
    {
        return new self($contact->line1, $contact->line2, $contact->city, $contact->postcode, $contact->country);
    }
}
