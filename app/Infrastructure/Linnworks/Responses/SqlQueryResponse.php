<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Responses;

use App\Infrastructure\Linnworks\Support\PascalCaseMapper;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Linnworks SQL query API response DTO.
 *
 * Maps response from Dashboards/ExecuteCustomScriptQuery endpoint.
 * Contains query success/failure status, result count, column schema,
 * and the actual result rows.
 *
 * @template-pattern Infrastructure Response DTO
 */
#[MapInputName(PascalCaseMapper::class)]
final class SqlQueryResponse extends Data
{
    /**
     * @param list<SqlQueryColumnResponse> $columns Column metadata describing result schema
     * @param list<array<string, mixed>> $results Query result rows
     */
    public function __construct(
        public readonly bool $isError,
        public readonly int $totalResults,
        #[DataCollectionOf(SqlQueryColumnResponse::class)]
        public readonly array $columns,
        public readonly array $results,
    ) {}
}
