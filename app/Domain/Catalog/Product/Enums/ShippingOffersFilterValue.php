<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Enums;

use App\Domain\Catalog\Product\Contracts\ShopwiredFilterValueInterface;
use App\Domain\Exceptions\Data\InvalidEnumValueException;

/**
 * Shipping Offers filter values for ShopWired product filter `optionNo = 20`.
 *
 * Dedicated slot — no admin-maintained siblings. Driven entirely by the
 * `free_delivery` custom field on `shopwired.products`.
 */
enum ShippingOffersFilterValue: string implements ShopwiredFilterValueInterface
{
    case FreeStandardDelivery = 'Free Standard Delivery';
    case FreeExpressDelivery = 'Free Express Delivery';

    /** @throws InvalidEnumValueException */
    public static function fromString(string $value): self
    {
        return self::tryFrom($value)
            ?? throw InvalidEnumValueException::invalidBackingValue(self::class, $value);
    }

    /**
     * Parse a PostgreSQL `jsonb` array string (e.g. `["Free Standard Delivery"]`) into enum cases.
     *
     * Uses `jsonb` instead of `text[]` because Shipping Offers filter values contain
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
