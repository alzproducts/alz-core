<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * Globally Unique Identifier (UUID format).
 *
 * Used for external system identifiers like Linnworks stockItemId.
 * Validates UUID v4 format.
 *
 * @template-pattern Domain Value Object
 */
final readonly class Guid
{
    public function __construct(
        public string $value,
    ) {
        Assert::uuid($value, 'Invalid GUID format: %s');
    }

    /**
     * Create from a trusted source.
     *
     * Note: Still validates (constructor runs Assert::uuid). Use when the value
     * is known-good (e.g., from database) and validation cost is acceptable.
     */
    public static function fromTrusted(string $value): self
    {
        return new self($value);
    }

    /**
     * Check equality with another GUID.
     */
    public function equals(self $other): bool
    {
        return \mb_strtolower($this->value) === \mb_strtolower($other->value);
    }
}
