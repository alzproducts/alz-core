<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Support;

use App\Domain\ValueObjects\Guid;
use InvalidArgumentException;

/**
 * SQL query building utilities for Linnworks SQL Server queries.
 *
 * Provides consistent escaping and query construction helpers for the
 * Dashboards/ExecuteCustomScriptQuery endpoint.
 *
 * @template-pattern Infrastructure Support Utility
 */
final class SqlQueryBuilder
{
    private const string TRANSACTION_ISOLATION = 'SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED;';

    /**
     * Wrap SQL with transaction isolation level prefix.
     *
     * READ UNCOMMITTED is required to avoid blocking locks on Linnworks'
     * production database during queries.
     */
    public static function withIsolationLevel(string $sql): string
    {
        return self::TRANSACTION_ISOLATION . ' ' . $sql;
    }

    /**
     * Escape a single string value for SQL Server.
     *
     * Handles single quote escaping by doubling quotes.
     */
    public static function escapeString(string $value): string
    {
        return "'" . \str_replace("'", "''", $value) . "'";
    }

    /**
     * Build IN clause from string array.
     *
     * @param list<string> $values
     *
     * @throws InvalidArgumentException When values array is empty
     */
    public static function buildInClause(array $values): string
    {
        if ($values === []) {
            throw new InvalidArgumentException('IN clause values cannot be empty');
        }

        $escaped = \array_map(self::escapeString(...), $values);

        return '(' . \implode(', ', $escaped) . ')';
    }

    /**
     * Build IN clause from GUIDs.
     *
     * @param list<Guid> $guids
     *
     * @throws InvalidArgumentException When guids array is empty
     */
    public static function buildGuidInClause(array $guids): string
    {
        $strings = \array_map(
            static fn(Guid $guid): string => $guid->value,
            $guids,
        );

        return self::buildInClause($strings);
    }
}
