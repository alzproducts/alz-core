<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Responses;

use App\Infrastructure\Linnworks\Support\PascalCaseMapper;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Linnworks SKU to StockItemId mapping response DTO.
 *
 * Maps response from /api/Inventory/GetStockItemIdsBySKU endpoint.
 *
 * @template-pattern Infrastructure Response DTO
 */
#[MapInputName(PascalCaseMapper::class)]
final class SkuStockIdMapping extends Data
{
    public function __construct(
        public readonly string $stockItemId,
        #[MapInputName('SKU')]
        public readonly string $sku,
    ) {}
}
