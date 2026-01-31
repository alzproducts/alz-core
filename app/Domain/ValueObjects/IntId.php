<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * Integer identifier value object.
 *
 * Used for external system identifiers that are positive integers,
 * such as ShopWired product/variation IDs. Provides type safety
 * when APIs accept either SKU strings or integer IDs.
 *
 * @template-pattern Domain Value Object
 */
final readonly class IntId
{
    private function __construct(
        public int $value,
    ) {
        Assert::positiveInteger($value, 'IntId must be a positive integer, got: %s');
    }

    /**
     * Create from an integer value.
     */
    public static function from(int $value): self
    {
        return new self($value);
    }

    /**
     * Create from a trusted source.
     *
     * Still validates (constructor runs Assert). Use when the value
     * is known-good (e.g., from database) and validation cost is acceptable.
     */
    public static function fromTrusted(int $value): self
    {
        return new self($value);
    }

    /**
     * Check equality with another IntId.
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
