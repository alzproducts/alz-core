<?php

declare(strict_types=1);

namespace Tests\Unit\Static\Support;

/**
 * AC7 for `database/reports/vat-replay.sql`: one read-only SELECT whose output lists name no PII, order identifier,
 * price, cost or margin. Output lists are the final SELECT and every CTE not named in REVIEWED_CTES. Comments are
 * ignored; string literals never count as identifiers.
 */
final class VatReplaySqlCheck
{
    public const string PATH = 'database/reports/vat-replay.sql';

    /**
     * Computation CTEs whose select lists may carry order data; the final SELECT reads only aggregates of them.
     */
    public const array REVIEWED_CTES = [
        'src_orders', 'src_lines', 'src_line_facts', 'src_cohort',
        'm1_items', 'm1_orders', 'm1_results',
        'zap_lines', 'zap_line_checks', 'zap_items', 'zap_orders', 'zap_results',
    ];

    public const array OUTPUT_KEYS = ['m1', 'zap_as_built', 'baseline', 'sql_sha256'];

    private const array BANNED_EXACT = ['reference', 'external_id', 'delivery_country', 'tax_value', 'total'];

    private const array BANNED_SUBSTRINGS = ['price', 'cost', 'margin', 'name', 'email', 'phone', 'postcode', 'address', 'city', 'country', 'customer'];

    private const array ROW_EXPANSIONS = ['to_jsonb', 'to_json', 'row_to_json', 'jsonb_agg', 'json_agg', 'array_agg'];

    private const array FORBIDDEN_KEYWORDS = [
        'insert', 'update', 'delete', 'merge', 'create', 'alter', 'drop', 'truncate', 'grant', 'revoke', 'copy',
        'call', 'do', 'execute', 'prepare', 'vacuum', 'lock', 'listen', 'notify', 'refresh', 'set', 'reset',
    ];

    private const array FORBIDDEN_FUNCTIONS = ['setval', 'nextval', 'set_config', 'pg_terminate_backend', 'pg_cancel_backend', 'pg_sleep'];

    private const array FORBIDDEN_PREFIXES = ['lo_', 'dblink'];

    private const array CLAUSES = [
        'select' => 'select',
        'from' => 'other',
        'join' => 'other',
        'where' => 'other',
        'on' => 'other',
        'group' => 'other',
        'having' => 'other',
        'order' => 'other',
        'with' => 'other',
        'union' => 'other',
        'values' => 'other',
    ];

    /**
     * @return list<string>
     */
    public static function violations(?string $sql): array
    {
        if ($sql === null) {
            return ['replay SQL is missing'];
        }

        $tokens = self::tokens($sql);
        $words = \array_map(static fn(string $token): string => self::isLiteral($token) ? "''" : \mb_strtolower($token), $tokens);

        if (!\in_array('select', $words, true)) {
            return ['replay SQL has no SELECT'];
        }

        $closers = self::closers($tokens);
        $ctes = self::ctes($words, $closers);

        return [
            ...self::statementViolations($words),
            ...self::callViolations($words),
            ...self::outputListViolations($words, $ctes),
            ...self::keyViolations($tokens, $words, $ctes),
            ...self::zapRoundViolations($words, $ctes),
        ];
    }

    /**
     * @return list<string>
     */
    private static function tokens(string $sql): array
    {
        $stripped = (string) \preg_replace("/--[^\n]*/", '', $sql);
        \preg_match_all("/'(?:[^']|'')*'|[A-Za-z_][A-Za-z0-9_]*(?:\\.[A-Za-z_][A-Za-z0-9_]*)*|\\S/", $stripped, $matches);

        return $matches[0];
    }

    private static function isLiteral(string $token): bool
    {
        return \str_starts_with($token, "'");
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
     * Top-level CTEs as name => [first body index, closing index].
     *
     * @param list<string> $words
     * @param array<int, int> $closers
     *
     * @return array<string, array{int, int}>
     */
    private static function ctes(array $words, array $closers): array
    {
        $ctes = [];
        $index = 0;

        while ($index < \count($words) - 2) {
            $starts = \in_array($words[$index - 1] ?? '', ['with', ','], true) && $words[$index + 1] === 'as' && isset($closers[$index + 2]);

            if ($starts) {
                $ctes[$words[$index]] = [$index + 3, $closers[$index + 2]];
                $index = $closers[$index + 2];
            }

            ++$index;
        }

        return $ctes;
    }

    /**
     * @param list<string> $words
     *
     * @return list<string>
     */
    private static function statementViolations(array $words): array
    {
        $violations = \in_array($words[0], ['with', 'select'], true) ? [] : ['replay SQL does not start with WITH or SELECT'];

        foreach ($words as $index => $word) {
            if ($word === ';' && $index !== \count($words) - 1) {
                $violations[] = 'replay SQL holds a second statement';
            } elseif ($word === '\\') {
                $violations[] = 'replay SQL holds a psql meta-command';
            } elseif (\in_array($word, self::FORBIDDEN_KEYWORDS, true)) {
                $violations[] = 'replay SQL holds a forbidden keyword: ' . $word;
            }
        }

        return $violations;
    }

    /**
     * @param list<string> $words
     *
     * @return list<string>
     */
    private static function callViolations(array $words): array
    {
        $violations = [];

        foreach ($words as $index => $word) {
            $forbidden = \in_array($word, self::FORBIDDEN_FUNCTIONS, true)
                || \array_any(self::FORBIDDEN_PREFIXES, static fn(string $prefix): bool => \str_starts_with($word, $prefix));

            if ($forbidden && ($words[$index + 1] ?? '') === '(') {
                $violations[] = 'replay SQL calls a forbidden function: ' . $word;
            }
        }

        return $violations;
    }

    /**
     * @param list<string> $words
     * @param array<string, array{int, int}> $ctes
     *
     * @return list<string>
     */
    private static function outputListViolations(array $words, array $ctes): array
    {
        $reviewed = \array_intersect_key($ctes, \array_flip(self::REVIEWED_CTES));
        $violations = [];

        foreach (self::selectListIndexes($words) as $index) {
            if (\array_any($reviewed, static fn(array $span): bool => $index >= $span[0] && $index <= $span[1])) {
                continue;
            }

            $violations = [...$violations, ...self::outputTokenViolations($words, $index)];
        }

        return $violations;
    }

    /**
     * @param list<string> $words
     *
     * @return list<string>
     */
    private static function outputTokenViolations(array $words, int $index): array
    {
        $word = $words[$index];
        $segments = \explode('.', $word);
        $identifier = \end($segments);

        if ($word === '*') {
            return ['output select list holds a row expansion: *'];
        }

        if (\in_array($word, self::ROW_EXPANSIONS, true)) {
            return ['output select list holds a row expansion: ' . $word];
        }

        if (\in_array($identifier, self::BANNED_EXACT, true) || \array_any(self::BANNED_SUBSTRINGS, static fn(string $banned): bool => \str_contains($identifier, $banned))) {
            return ['output select list names a banned identifier: ' . $word];
        }

        return [];
    }

    /**
     * Indexes of every token in a SELECT list, at any depth, including aliases and function arguments.
     *
     * @param list<string> $words
     *
     * @return list<int>
     */
    private static function selectListIndexes(array $words): array
    {
        $frames = ['other'];
        $found = [];

        foreach ($words as $index => $word) {
            $frame = \array_key_last($frames);

            if ($word === '(') {
                $frames[] = $frames[$frame];
            } elseif ($word === ')' && $frame > 0) {
                \array_pop($frames);
            } elseif (isset(self::CLAUSES[$word])) {
                $frames[$frame] = self::CLAUSES[$word];
            } elseif ($frames[$frame] === 'select') {
                $found[] = $index;
            }
        }

        return $found;
    }

    /**
     * Each output key must be a `'key',` pair in the final SELECT, after the last CTE.
     *
     * @param list<string> $tokens
     * @param list<string> $words
     * @param array<string, array{int, int}> $ctes
     *
     * @return list<string>
     */
    private static function keyViolations(array $tokens, array $words, array $ctes): array
    {
        $start = $ctes === [] ? 0 : \max(\array_column($ctes, 1)) + 1;
        $keys = [];

        for ($index = $start; $index < \count($tokens) - 1; ++$index) {
            if (self::isLiteral($tokens[$index]) && $words[$index + 1] === ',') {
                $keys[] = \mb_substr($tokens[$index], 1, -1);
            }
        }

        return \array_values(\array_map(
            static fn(string $key): string => 'replay output lacks the key: ' . $key,
            \array_diff(self::OUTPUT_KEYS, $keys),
        ));
    }

    /**
     * @param list<string> $words
     * @param array<string, array{int, int}> $ctes
     *
     * @return list<string>
     */
    private static function zapRoundViolations(array $words, array $ctes): array
    {
        $violations = [];

        foreach ($ctes as $name => [$first, $last]) {
            $body = \array_slice($words, $first, $last - $first);

            if (\str_starts_with($name, 'zap_') && \array_any(\array_keys($body), static fn(int $i): bool => $body[$i] === 'round' && ($body[$i + 1] ?? '') === '(')) {
                $violations[] = 'zap_ CTE calls round(): ' . $name;
            }
        }

        return $violations;
    }
}
