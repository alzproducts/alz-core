<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Enums;

use App\Domain\Exceptions\Data\InvalidEnumValueException;

/**
 * Rating filter threshold values for ShopWired product filters.
 *
 * Products with weighted average rating >= 4.0 qualify for FourStars;
 * products >= 4.5 qualify for both FourStars and FourAndHalfStars.
 */
enum RatingFilterValue: string
{
    case FourStars = '4';
    case FourAndHalfStars = '4.5';

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
     * Parse a PostgreSQL text array literal (e.g. '{4,4.5}') into enum cases.
     *
     * Assumes simple unquoted values (no spaces or special chars). Safe for the
     * current rating filter values ('4', '4.5') produced by the SQL view's CASE.
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

    /**
     * Convert a list of enum cases to their string backing values.
     *
     * @param list<self> $values
     *
     * @return list<string>
     */
    public static function toStringArray(array $values): array
    {
        return \array_map(static fn(self $v): string => $v->value, $values);
    }
}
