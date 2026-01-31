<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Contracts;

use App\Infrastructure\Linnworks\Responses\SqlQueryResponse;

/**
 * A self-contained Linnworks SQL query with typed response mapping.
 *
 * Query objects encapsulate:
 * - SQL query construction with proper escaping
 * - Response mapping to typed results
 *
 * This enables type-safe query execution via DashboardsClient::execute().
 *
 * @template TResult The typed result returned by mapResponse()
 *
 * @template-pattern Query Object
 */
interface LinnworksQueryInterface
{
    /**
     * Build the complete SQL query (including isolation level).
     */
    public function buildSql(): string;

    /**
     * Map the raw query response to a typed result.
     *
     * @return TResult
     */
    public function mapResponse(SqlQueryResponse $response): mixed;
}
