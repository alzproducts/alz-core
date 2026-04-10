<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Enums;

use App\Domain\Catalog\Product\Contracts\ShopwiredFilterValueInterface;
use App\Domain\Exceptions\Data\InvalidEnumValueException;

/**
 * VAT-relief filter values for ShopWired product filters.
 *
 * Products with `shopwired.products.vat_relief = true` qualify for `Yes`.
 * Products with `false` have no value (filter absent). `null` rows are never
 * synced — see the `products_with_changed_vat_relief_filters` view.
 */
enum VatReliefFilterValue: string implements ShopwiredFilterValueInterface
{
    case Yes = 'Yes';

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
     * Parse a PostgreSQL text array literal (e.g. '{Yes}') into enum cases.
     *
     * @return list<self>
     *
     * @throws InvalidEnumValueException
     */
    public static function fromPostgresArray(string $pgArray): array
    {
        $trimmed = \mb_trim($pgArray, '{}');

        if ($trimmed === '') {
            return [];
        }

        return \array_map(self::fromString(...), \explode(',', $trimmed));
    }
}
