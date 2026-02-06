<?php

declare(strict_types=1);

namespace App\Domain\ContactSubmission\Enums;

use App\Domain\Exceptions\Data\InvalidEnumValueException;

/**
 * Source of the selected product in contact form.
 *
 * Indicates whether the user selected a product from their
 * recently viewed history or their order history.
 */
enum ProductSource: string
{
    case RecentlyViewed = 'recently_viewed';
    case RecentlyOrdered = 'recently_ordered';

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::RecentlyViewed => 'Recently Viewed',
            self::RecentlyOrdered => 'Recently Ordered',
        };
    }

    /**
     * Create from backing value with domain exception.
     *
     * Use instead of ::from() when you want domain exceptions
     * rather than PHP's ValueError.
     *
     * @throws InvalidEnumValueException When value doesn't match any case
     */
    public static function fromValue(string $value): self
    {
        return self::tryFrom($value)
            ?? throw InvalidEnumValueException::invalidBackingValue(self::class, $value);
    }
}
