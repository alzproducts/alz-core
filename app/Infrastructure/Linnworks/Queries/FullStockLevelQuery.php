<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Queries;

use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Inventory\ValueObjects\ItemStockLevel;
use App\Infrastructure\Linnworks\Responses\SqlQueryResponse;
use App\Infrastructure\Linnworks\Responses\StockLevelLocationResponse;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Row structure for FullStockLevelQuery results.
 *
 * @internal Implementation detail of FullStockLevelQuery
 */
final class FullStockLevelRow extends Data
{
    public function __construct(
        #[MapInputName('sku')]
        public readonly string $sku,
        #[MapInputName('stock')]
        public readonly int $stock,
    ) {}

    public function toDomain(): ItemStockLevel
    {
        return new ItemStockLevel(
            sku: Sku::fromTrusted($this->sku),
            quantity: $this->stock,
        );
    }
}

/**
 * Query all stock levels from Linnworks View_FullStockLevels.
 *
 * Returns available stock (Level_LessOrderBook, floored at 0) for the
 * default stock location.
 *
 * @extends AbstractLinnworksQuery<list<ItemStockLevel>>
 *
 * @template-pattern Query Object
 */
final readonly class FullStockLevelQuery extends AbstractLinnworksQuery
{
    protected function buildQueryBody(): string
    {
        $locationId = StockLevelLocationResponse::DEFAULT_LOCATION_ID;

        return <<<SQL
            SELECT
                ItemNumber AS 'sku',
                CASE WHEN Level_LessOrderBook < 0 THEN 0 ELSE Level_LessOrderBook END AS 'stock'
            FROM [View_FullStockLevels]
            WHERE pkStockLocationId = '{$locationId}'
            SQL;
    }

    /**
     * @return list<ItemStockLevel>
     */
    public function mapResponse(SqlQueryResponse $response): array
    {
        return \array_map(
            static fn(array $row): ItemStockLevel => FullStockLevelRow::from($row)->toDomain(),
            $response->results,
        );
    }
}
