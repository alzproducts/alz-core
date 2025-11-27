<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * Country Value Object.
 *
 * Represents a country with ISO code for customer addresses.
 */
final readonly class Country
{
    /**
     * @param string $name Country display name (e.g., "United Kingdom")
     * @param string $iso ISO 3166-1 alpha-2 code (e.g., "GB")
     */
    public function __construct(
        public string $name,
        public string $iso,
    ) {
        Assert::notEmpty($name, 'Country name cannot be empty');
        Assert::length($iso, 2, 'ISO code must be exactly 2 characters');
    }
}
