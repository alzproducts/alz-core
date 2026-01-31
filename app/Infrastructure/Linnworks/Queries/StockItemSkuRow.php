<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Queries;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Row structure for StockItemBySkuQuery results.
 *
 * Maps the SQL columns returned by the SKU lookup query.
 * Uses explicit MapInputName to match exact column names from SQL query.
 *
 * @internal Used only by StockItemBySkuQuery::mapResponse()
 */
final class StockItemSkuRow extends Data
{
    public function __construct(
        #[MapInputName('pkStockItemID')]
        public readonly string $stockItemId,
        #[MapInputName('ItemNumber')]
        public readonly string $sku,
    ) {}
}
