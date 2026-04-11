<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Enums;

use App\Domain\Catalog\Product\Contracts\ShopwiredFilterValueInterface;
use App\Domain\Exceptions\Data\InvalidEnumValueException;

/**
 * Shipping Options filter values for ShopWired product filter `optionNo = 25`.
 *
 * Dedicated slot — no admin-maintained siblings. Driven entirely by
 * stock availability: parent `stock > 0` OR any variation `stock > 0`.
 *
 * @see ShippingOffersFilterValue for the separate "Shipping Offers" filter (optionNo 20, free_delivery field)
 */
enum ShippingOptionsFilterValue: string implements ShopwiredFilterValueInterface
{
    case NextDayDeliveryAvailable = 'Next Day Delivery Available';

    /** @throws InvalidEnumValueException */
    public static function fromString(string $value): self
    {
        return self::tryFrom($value)
            ?? throw InvalidEnumValueException::invalidBackingValue(self::class, $value);
    }

    /**
     * Parse a PostgreSQL `jsonb` array string (e.g. `["Next Day Delivery Available"]`) into enum cases.
     *
     * Uses `jsonb` instead of `text[]` because Shipping Options filter values contain
     * whitespace — Postgres `text[]` output quotes those elements and introduces
     * escape-handling edge cases that JSON sidesteps entirely.
     *
     * @return list<self>
     *
     * @throws InvalidEnumValueException
     */
    public static function fromJsonArray(string $json): array
    {
        /** @var mixed $decoded */
        $decoded = \json_decode($json, associative: true);

        if (! \is_array($decoded)) {
            throw InvalidEnumValueException::invalidBackingValue(self::class, $json);
        }

        return \array_map(
            static fn(mixed $value): self => \is_string($value)
                ? self::fromString($value)
                : throw InvalidEnumValueException::invalidBackingValue(self::class, $json),
            \array_values($decoded),
        );
    }
}
