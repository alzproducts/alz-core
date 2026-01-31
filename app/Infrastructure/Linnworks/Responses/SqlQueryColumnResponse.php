<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Responses;

use App\Infrastructure\Linnworks\Support\PascalCaseMapper;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Column metadata from Linnworks SQL query response.
 *
 * Describes the schema of result columns returned by
 * Dashboards/ExecuteCustomScriptQuery endpoint.
 *
 * @template-pattern Infrastructure Response DTO
 */
#[MapInputName(PascalCaseMapper::class)]
final class SqlQueryColumnResponse extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
    ) {}
}
