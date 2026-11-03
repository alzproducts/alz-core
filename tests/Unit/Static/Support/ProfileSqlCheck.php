<?php

declare(strict_types=1);

namespace Tests\Unit\Static\Support;

/**
 * AC11 for the profile export SQL: a column naming a price, cost, margin, title, SKU, supplier, email or name
 * may appear in a SELECT list only inside `count(...)` (which covers `count(DISTINCT ...)`) or as a reference to
 * a `count(...)` alias. FROM, JOIN, WHERE, ON, GROUP BY, HAVING and ORDER BY may name them: they shape which rows
 * are counted but return no values. Comments and string literals are ignored.
 */
final class ProfileSqlCheck
{
    /**
     * Subqueries whose SELECT list may name a banned column, keyed by their normalised text (string literals
     * blanked, one space between tokens); editing one makes it fail until it is reviewed again.
     */
    public const array EXEMPT_QUERIES = [
        'SELECT sku FROM p WHERE sku IS NOT NULL UNION SELECT sku FROM v WHERE sku IS NOT NULL' => 'the all_skus CTE, read only by EXISTS predicates',
        'SELECT title FROM p GROUP BY title HAVING count ( * ) > 1' => 'derived table consumed only by count(*)',
        "SELECT jsonb_object_agg ( coalesce ( supplier_currency , '' ) , n ) FROM ( SELECT supplier_currency , count ( * ) AS n FROM sis GROUP BY 1 ) h" => 'currency codes (GBP, USD), not supplier names',
    ];

    private const array CLAUSES = [
        'select' => 'select',
        'from' => 'from',
        'join' => 'from',
        'where' => 'predicate',
        'on' => 'predicate',
        'group' => 'predicate',
        'having' => 'predicate',
        'order' => 'predicate',
        'with' => 'none',
        'union' => 'none',
    ];

    /**
     * @return list<string>
     */
    public static function violations(string $sql): array
    {
        $tokens = self::tokens($sql);

        if (!\in_array('select', \array_map(\strtolower(...), $tokens), true)) {
            return ['export SQL has no SELECT'];
        }

        $closers = self::closers($tokens);
        $countAliases = self::countAliases($tokens, $closers);
        $violations = [];

        foreach (self::selectListColumns($tokens) as [$index, $enclosing]) {
            $column = \mb_strtolower($tokens[$index]);

            if (isset($countAliases[$column]) || self::insideExemptQuery($tokens, $closers, $enclosing)) {
                continue;
            }

            $violations[] = 'SELECT list names a banned column outside count(): ' . $tokens[$index];
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private static function tokens(string $sql): array
    {
        $stripped = (string) \preg_replace(["/--[^\n]*/", "/'(?:[^']|'')*'/"], ['', "''"], $sql);
        \preg_match_all('/[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*|\'\'|\S/', $stripped, $matches);

        return $matches[0];
    }

    /**
     * @param list<string> $tokens
     *
     * @return array<int, int> index of each `(` => index of its `)`
     */
    private static function closers(array $tokens): array
    {
        $open = [];
        $closers = [];

        foreach ($tokens as $index => $token) {
            if ($token === '(') {
                $open[] = $index;
            } elseif ($token === ')' && $open !== []) {
                $closers[\array_pop($open)] = $index;
            }
        }

        return $closers;
    }

    /**
     * @param list<string> $tokens
     * @param array<int, int> $closers
     *
     * @return array<string, true>
     */
    private static function countAliases(array $tokens, array $closers): array
    {
        $aliases = [];

        foreach ($tokens as $index => $token) {
            $close = $closers[$index + 1] ?? null;

            if (\mb_strtolower($token) === 'count' && $close !== null && \mb_strtolower($tokens[$close + 1] ?? '') === 'as' && isset($tokens[$close + 2])) {
                $aliases[\mb_strtolower($tokens[$close + 2])] = true;
            }
        }

        return $aliases;
    }

    /**
     * Banned columns in a SELECT list outside `count(...)`, each with the indexes of its enclosing `(`.
     *
     * @param list<string> $tokens
     *
     * @return list<array{int, list<int>}>
     */
    private static function selectListColumns(array $tokens): array
    {
        $frames = [['clause' => 'none', 'count' => false, 'open' => -1]];
        $found = [];

        foreach ($tokens as $index => $token) {
            $lower = \mb_strtolower($token);
            $frame = \array_key_last($frames);

            if ($token === '(') {
                $frames[] = ['clause' => $frames[$frame]['clause'], 'count' => $frames[$frame]['count'] || \mb_strtolower($tokens[$index - 1] ?? '') === 'count', 'open' => $index];
            } elseif ($token === ')' && $frame > 0) {
                \array_pop($frames);
            } elseif (isset(self::CLAUSES[$lower])) {
                $frames[$frame]['clause'] = self::CLAUSES[$lower];
            } elseif ($frames[$frame]['clause'] === 'select' && !$frames[$frame]['count'] && \mb_strtolower($tokens[$index - 1] ?? '') !== 'as' && self::isBanned($lower)) {
                $found[] = [$index, \array_values(\array_filter(\array_column($frames, 'open'), static fn(int $open): bool => $open >= 0))];
            }
        }

        return $found;
    }

    /**
     * @param list<string> $tokens
     * @param array<int, int> $closers
     * @param list<int> $enclosing
     */
    private static function insideExemptQuery(array $tokens, array $closers, array $enclosing): bool
    {
        return \array_any($enclosing, static function (int $open) use ($tokens, $closers): bool {
            $close = $closers[$open] ?? \count($tokens);

            return isset(self::EXEMPT_QUERIES[\implode(' ', \array_slice($tokens, $open + 1, $close - $open - 1))]);
        });
    }

    private static function isBanned(string $identifier): bool
    {
        return \array_any(ProfileJsonCheck::BANNED_WORDS, static fn(string $word): bool => \str_contains($identifier, $word));
    }
}
