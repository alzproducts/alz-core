<?php

declare(strict_types=1);

namespace Tests\Unit\Static\Support;

/**
 * COR-297 AC1 and AC2 for the orders profile export SQL: one read-only statement whose SELECT lists name no PII or
 * reference column outside `count(...)`, never select `order_placed_at` or `delivery_date` outside an aggregate, and
 * never expand a whole row. FROM, JOIN, WHERE, ON, GROUP BY, HAVING and ORDER BY may name any column: they shape which
 * rows are counted but return no values. Comments are ignored; string literals never count as identifiers.
 */
final class OrdersProfileSqlCheck
{
    public const string PATH = 'database/seed-profile/orders-profile-export.sql';

    /** Matched as substrings of a column's last name segment. */
    public const array BANNED_WORDS = [
        'name', 'email', 'telephone', 'company', 'address', 'city', 'province', 'state', 'postcode', 'reference',
        'external_id', 'customer_id', 'birth', 'device', 'transaction', 'url', 'comments', 'custom_fields', 'variation',
        'title', 'sku', 'cost',
    ];

    public const array AGGREGATE_ONLY = ['order_placed_at', 'delivery_date'];

    private const array AGGREGATES = ['count', 'min', 'max', 'sum', 'avg'];

    private const array ROW_EXPANSIONS = ['to_jsonb', 'to_json', 'row_to_json'];

    private const array FORBIDDEN_KEYWORDS = [
        'insert', 'update', 'delete', 'merge', 'create', 'alter', 'drop', 'truncate', 'grant', 'revoke', 'copy',
        'call', 'do', 'execute', 'prepare', 'vacuum', 'lock', 'listen', 'notify', 'refresh', 'set', 'reset',
    ];

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
    ];

    /**
     * @return list<string>
     */
    public static function violations(?string $sql): array
    {
        if ($sql === null) {
            return ['orders profile SQL is missing'];
        }

        $words = self::words($sql);

        if (!\in_array('select', $words, true)) {
            return ['orders profile SQL has no SELECT'];
        }

        return [...self::statementViolations($words), ...self::selectListViolations($words)];
    }

    /**
     * Lower-cased tokens, with every string literal collapsed to `''`.
     *
     * @return list<string>
     */
    private static function words(string $sql): array
    {
        $stripped = (string) \preg_replace(["/--[^\n]*/", "/'(?:[^']|'')*'/"], ['', "''"], $sql);
        \preg_match_all('/[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*|\'\'|\S/', $stripped, $matches);

        return \array_map(\mb_strtolower(...), $matches[0]);
    }

    /**
     * @param list<string> $words
     *
     * @return list<string>
     */
    private static function statementViolations(array $words): array
    {
        $violations = \in_array($words[0], ['with', 'select'], true) ? [] : ['orders profile SQL does not start with WITH or SELECT'];

        foreach ($words as $index => $word) {
            if ($word === ';' && $index !== \count($words) - 1) {
                $violations[] = 'orders profile SQL holds a second statement';
            } elseif ($word === '\\') {
                $violations[] = 'orders profile SQL holds a psql meta-command';
            } elseif (\in_array($word, self::FORBIDDEN_KEYWORDS, true)) {
                $violations[] = 'orders profile SQL holds a forbidden keyword: ' . $word;
            }
        }

        return $violations;
    }

    /**
     * @param list<string> $words
     *
     * @return list<string>
     */
    private static function selectListViolations(array $words): array
    {
        $frames = [['clause' => 'other', 'count' => false, 'aggregate' => false]];
        $violations = [];

        foreach ($words as $index => $word) {
            $frame = \array_key_last($frames);
            $previous = $words[$index - 1] ?? '';

            if ($word === '(') {
                $frames[] = [
                    'clause' => $frames[$frame]['clause'],
                    'count' => $frames[$frame]['count'] || $previous === 'count',
                    'aggregate' => $frames[$frame]['aggregate'] || \in_array($previous, self::AGGREGATES, true),
                ];
            } elseif ($word === ')' && $frame > 0) {
                \array_pop($frames);
            } elseif (isset(self::CLAUSES[$word])) {
                $frames[$frame]['clause'] = self::CLAUSES[$word];
            } elseif ($frames[$frame]['clause'] === 'select' && $previous !== 'as') {
                $violation = self::tokenViolation($word, $frames[$frame]['count'], $frames[$frame]['aggregate'], $words[$index + 1] ?? '');

                if ($violation !== null) {
                    $violations[] = $violation;
                }
            }
        }

        return $violations;
    }

    private static function tokenViolation(string $word, bool $inCount, bool $inAggregate, string $next): ?string
    {
        $segments = \explode('.', $word);
        $column = \end($segments);

        if ($word === '*' && !$inCount) {
            return 'SELECT list holds a row expansion: *';
        }

        if (\in_array($word, self::ROW_EXPANSIONS, true) && $next === '(') {
            return 'SELECT list holds a row expansion: ' . $word;
        }

        if (!$inCount && \array_any(self::BANNED_WORDS, static fn(string $banned): bool => \str_contains($column, $banned))) {
            return 'SELECT list names a banned column outside count(): ' . $word;
        }

        if (!$inAggregate && \in_array($column, self::AGGREGATE_ONLY, true)) {
            return 'SELECT list names an order date outside an aggregate: ' . $word;
        }

        return null;
    }
}
