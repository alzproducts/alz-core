<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Enums;

use App\Domain\Catalog\Product\Contracts\ShopwiredFilterValueInterface;
use App\Domain\Exceptions\Data\InvalidEnumValueException;

/**
 * Offers filter values for ShopWired product filter `optionNo = 14`.
 *
 * Multi-value slot shared with sibling admin-maintained values (e.g. "Free Delivery").
 * Only `OnSale` is synced automatically from pricing state — the merge-preserving SQL
 * view (`catalog.products_with_changed_offers_filters`) rebuilds the desired slot
 * contents so siblings survive a dispatch.
 *
 * Canonical casing is title-case `"On Sale"`. Live DB contains legacy lowercase
 * `"On sale"` rows that will be normalised by the first sync run.
 */
enum OffersFilterValue: string implements ShopwiredFilterValueInterface
{
    case OnSale = 'On Sale';

    /**
     * Create from backing value with domain exception.
     *
     * @throws InvalidEnumValueException
     */
    public static function fromString(string $value): self
    {
        return self::tryFrom($value)
            ?? throw InvalidEnumValueException::invalidBackingValue(self::class, $value);
    }

    /**
     * Parse a PostgreSQL `jsonb` array string (e.g. `["On Sale"]`) into enum cases.
     *
     * Uses `jsonb` instead of `text[]` (the VAT-relief path) because Offers filter
     * values contain whitespace — Postgres `text[]` output quotes those elements and
     * introduces escape-handling edge cases that JSON sidesteps entirely.
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
