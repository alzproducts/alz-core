<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use Webmozart\Assert\Assert;

/**
 * Builds the quoted literals of a QBO query. QBO compares query strings ignoring case.
 */
final readonly class QuickBooksQueryEscaper
{
    public const int MAX_IN_VALUES = 100;

    public const int MAX_RESULTS = 1000;

    /**
     * A backslash escapes both `'` and itself, so `O'Brien\x` becomes `'O\'Brien\\x'`.
     */
    public static function literal(string $value): string
    {
        return "'" . \addcslashes($value, "'\\") . "'";
    }

    /**
     * @param non-empty-list<string> $values at most MAX_IN_VALUES
     */
    public static function inList(array $values): string
    {
        Assert::maxCount($values, self::MAX_IN_VALUES);

        return '(' . \implode(', ', \array_map(self::literal(...), $values)) . ')';
    }

    /**
     * Splits values into IN-list-sized chunks, dropping repeats that differ only in case (QBO would match them once).
     *
     * @param list<string> $values
     *
     * @return list<non-empty-list<string>>
     */
    public static function chunks(array $values): array
    {
        $distinct = [];
        foreach ($values as $value) {
            $distinct[\mb_strtolower($value)] ??= $value;
        }

        return \array_chunk(\array_values($distinct), self::MAX_IN_VALUES);
    }
}
