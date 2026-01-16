<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Responses;

use App\Infrastructure\Linnworks\Support\PascalCaseMapper;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Linnworks stock level location response DTO.
 *
 * Nested within StockLevelResponse to identify the warehouse location.
 */
#[MapInputName(PascalCaseMapper::class)]
final class StockLevelLocationResponse extends Data
{
    public const string DEFAULT_LOCATION_ID = '00000000-0000-0000-0000-000000000000';

    public function __construct(
        public readonly string $stockLocationId,
        public readonly int $stockLocationIntId,
        public readonly string $locationName,
    ) {}

    public function isDefaultLocation(): bool
    {
        return $this->stockLocationId === self::DEFAULT_LOCATION_ID;
    }
}
