<?php

declare(strict_types=1);

namespace App\Domain\Customer\ValueObjects;

/**
 * Customer Address Value Object.
 *
 * Represents a customer's address fields.
 */
final readonly class CustomerAddress
{
    public function __construct(
        public ?string $line1,
        public ?string $line2,
        public ?string $line3,
        public ?string $city,
        public ?string $province,
        public ?string $postcode,
    ) {}

    /**
     * Check if address has minimum required fields for shipping.
     */
    public function isShippable(): bool
    {
        return ($this->line1 !== null)
               && ($this->city !== null)
               && ($this->postcode !== null);
    }

    /**
     * Check if address is completely empty.
     */
    public function isEmpty(): bool
    {
        return ($this->line1 === null)
               && ($this->line2 === null)
               && ($this->line3 === null)
               && ($this->city === null)
               && ($this->province === null)
               && ($this->postcode === null);
    }
}
