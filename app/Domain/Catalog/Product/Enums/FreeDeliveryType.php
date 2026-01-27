<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Enums;

use ValueError;

/**
 * Free delivery tier designation for products.
 *
 * Represents the delivery tier a product qualifies for. The `None` case
 * represents clearing any designation (translates to null externally).
 */
enum FreeDeliveryType: string
{
    /** No free delivery - clears any existing designation */
    case None = 'none';

    /** Standard free delivery tier */
    case Standard = 'Standard';

    /** Express free delivery tier */
    case Express = 'Express';

    /**
     * Create from string value (case-insensitive for user input).
     *
     * @throws ValueError When value doesn't match any case
     */
    public static function fromString(string $value): self
    {
        $normalized = \mb_strtolower(\mb_trim($value));

        return match ($normalized) {
            'none', '' => self::None,
            'standard' => self::Standard,
            'express' => self::Express,
            default => throw new ValueError("Invalid free delivery type: {$value}"),
        };
    }

    /**
     * Get the value to persist/transmit, returning null for None.
     *
     * @return string|null null when clearing designation, string value otherwise
     */
    public function toNullableValue(): ?string
    {
        return $this === self::None ? null : $this->value;
    }

    /**
     * Check if this type represents clearing the designation.
     */
    public function isNone(): bool
    {
        return $this === self::None;
    }

    /**
     * Get values that can be actively selected (excludes None).
     *
     * @return list<string>
     */
    public static function selectableValues(): array
    {
        return [self::Standard->value, self::Express->value];
    }
}
