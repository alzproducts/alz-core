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
     * Create from nullable API fields, returning null when all fields are absent.
     *
     * Consolidates the "all-null = no address" check used across response DTOs and mappers.
     */
    public static function fromNullableFields(
        ?string $line1,
        ?string $line2,
        ?string $line3,
        ?string $city,
        ?string $province,
        ?string $postcode,
    ): ?self {
        $address = new self($line1, $line2, $line3, $city, $province, $postcode);

        return $address->isEmpty() ? null : $address;
    }

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
