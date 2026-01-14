<?php

declare(strict_types=1);

namespace App\Domain\Customer\ValueObjects;

use DateTimeImmutable;

/**
 * Customer Value Object.
 *
 * Represents a customer with all business-relevant properties.
 *
 * @see https://shopwired.readme.io/reference/listcustomers
 */
final readonly class Customer
{
    /**
     * @param int $id ShopWired customer ID (external identifier)
     * @param DateTimeImmutable $createdAt When the customer was created in ShopWired
     * @param array<string, mixed> $customFields Custom field key-value pairs
     */
    public function __construct(
        // External identifier (ShopWired ID)
        public int $id,
        public DateTimeImmutable $createdAt,

        // Identity
        public string $email,
        public string $firstName,
        public string $lastName,
        public ?string $companyName,

        // Classification
        public bool $isTrade,
        public bool $isActive,
        public ?bool $isCreditEnabled,

        // Contact
        public ?string $phone,
        public ?string $mobilePhone,
        public bool $acceptsMarketing,

        // Address
        public ?CustomerAddress $address,

        // Notes
        public ?string $notes,

        // Custom fields (embedded key-value pairs)
        public array $customFields = [],
    ) {}

    /**
     * Get customer's full name.
     */
    public function fullName(): string
    {
        return \mb_trim($this->firstName . ' ' . $this->lastName);
    }

    /**
     * Check if customer has a shippable address.
     */
    public function hasShippableAddress(): bool
    {
        return ($this->address !== null) && $this->address->isShippable();
    }
}
