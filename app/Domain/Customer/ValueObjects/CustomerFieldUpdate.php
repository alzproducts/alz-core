<?php

declare(strict_types=1);

namespace App\Domain\Customer\ValueObjects;

use App\Domain\Customer\Enums\CustomerUpdatableField;

/**
 * Represents a single field update for a ShopWired customer.
 *
 * Use static factory methods for type-safe construction.
 * The API field name mapping lives in Infrastructure.
 */
final readonly class CustomerFieldUpdate
{
    private function __construct(
        public CustomerUpdatableField $field,
        public string $value,
    ) {}

    public static function firstName(string $firstName): self
    {
        return new self(CustomerUpdatableField::FirstName, $firstName);
    }
}
