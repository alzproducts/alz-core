<?php

declare(strict_types=1);

namespace App\Domain\Customer\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * Customer Value Object.
 *
 * Represents a customer with all business-relevant properties.
 * Infrastructure fields (id, createdAt, audit flags) are excluded.
 *
 * @see https://shopwired.readme.io/reference/listcustomers
 */
final readonly class Customer
{
    /**
     * @param array<string, mixed> $customFields Custom field key-value pairs
     */
    public function __construct(
        // Identity
        public string $email,
        public string $firstName,
        public string $lastName,
        public ?string $companyName,

        // Classification
        public bool $isTrade,
        public bool $isActive,
        public ?bool $creditEnabled,

        // Pricing (trade-specific, null for regular customers)
        public ?float $discount,
        public ?float $costPriceMultiplier,

        // Contact
        public ?string $phone,
        public ?string $mobilePhone,
        public ?string $website,
        public ?string $vatNumber,
        public bool $acceptsMarketing,

        // Address
        public ?CustomerAddress $address,

        // Loyalty
        public int $rewardPoints,

        // Notes
        public ?string $notes,

        // Custom fields (embedded key-value pairs)
        public array $customFields = [],
    ) {
        if ($discount !== null) {
            Assert::greaterThanEq($discount, 0, 'Discount cannot be negative');
        }
        if ($costPriceMultiplier !== null) {
            Assert::greaterThanEq($costPriceMultiplier, 0, 'Cost price multiplier cannot be negative');
        }
        Assert::greaterThanEq($rewardPoints, 0, 'Reward points cannot be negative');
    }

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
