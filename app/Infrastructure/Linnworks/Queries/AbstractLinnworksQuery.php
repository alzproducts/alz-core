<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Queries;

use App\Infrastructure\Linnworks\Contracts\LinnworksQueryInterface;
use App\Infrastructure\Linnworks\Support\SqlQueryBuilder;

/**
 * Base class for Linnworks SQL queries.
 *
 * Enforces consistent query building via Template Method pattern:
 * - buildSql() is final, always applies READ UNCOMMITTED isolation level
 * - Subclasses implement buildQueryBody() for the actual SQL
 *
 * This ensures all queries use the required isolation level to avoid
 * blocking locks on Linnworks' production database.
 *
 * @template TResult
 *
 * @implements LinnworksQueryInterface<TResult>
 *
 * @template-pattern Template Method
 */
abstract readonly class AbstractLinnworksQuery implements LinnworksQueryInterface
{
    /**
     * Build complete SQL with isolation level prefix.
     *
     * Final to enforce consistent behavior across all queries.
     */
    final public function buildSql(): string
    {
        return SqlQueryBuilder::withIsolationLevel($this->buildQueryBody());
    }

    /**
     * Build the query body WITHOUT isolation level prefix.
     *
     * Implementers provide only the actual SQL statement.
     */
    abstract protected function buildQueryBody(): string;
}
