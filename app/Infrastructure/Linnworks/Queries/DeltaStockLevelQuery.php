<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Queries;

use App\Application\Inventory\DTOs\StockLevelDeltaDTO;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Infrastructure\Linnworks\Responses\SqlQueryResponse;
use App\Infrastructure\Linnworks\Responses\StockLevelLocationResponse;
use App\Infrastructure\Linnworks\Support\SqlQueryBuilder;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Row structure for DeltaStockLevelQuery results.
 *
 * @internal Implementation detail of DeltaStockLevelQuery
 */
final class DeltaStockLevelRow extends Data
{
    public function __construct(
        #[MapInputName('sku')]
        public readonly string $sku,
        #[MapInputName('level')]
        public readonly int $level,
        #[MapInputName('lastUpdateDate')]
        public readonly string $lastUpdateDate,
    ) {}

    public function toDomain(): StockLevelDeltaDTO
    {
        return new StockLevelDeltaDTO(
            sku: Sku::fromTrusted($this->sku),
            level: $this->level,
            lastUpdateDate: CarbonImmutable::parse($this->lastUpdateDate),
        );
    }
}

/**
 * Query stock levels changed since a given datetime.
 *
 * Filters StockLevel by LastUpdateDate to return only recently-changed items.
 * Excludes archived items and non-default locations.
 * Results are ordered ASC so the last element holds the latest update date.
 *
 * @extends AbstractLinnworksQuery<list<StockLevelDeltaDTO>>
 *
 * @template-pattern Query Object
 */
final readonly class DeltaStockLevelQuery extends AbstractLinnworksQuery
{
    public function __construct(
        private DateTimeImmutable $since,
    ) {}

    protected function buildQueryBody(): string
    {
        $locationId = StockLevelLocationResponse::DEFAULT_LOCATION_ID;
        $escapedDate = SqlQueryBuilder::escapeString($this->since->format('Y-m-d H:i:s'));

        return <<<SQL
            SELECT
                si.ItemNumber AS 'sku',
                CASE WHEN (sl.Quantity - sl.InOrderBook) < 0 THEN 0 ELSE (sl.Quantity - sl.InOrderBook) END AS 'level',
                CAST(sl.LastUpdateDate AS DATETIME2(0)) AS 'lastUpdateDate'
            FROM StockLevel AS sl
            INNER JOIN [StockItem] AS si ON si.pkStockItemId = sl.fkStockItemId
            WHERE sl.fkStockLocationId = '{$locationId}'
              AND CAST(sl.LastUpdateDate AS DATETIME2(0)) > {$escapedDate}
              AND si.IsArchived = 'False'
            ORDER BY sl.LastUpdateDate ASC
            SQL;
    }

    /**
     * @return list<StockLevelDeltaDTO>
     */
    public function mapResponse(SqlQueryResponse $response): array
    {
        return \array_map(
            static fn(array $row): StockLevelDeltaDTO => DeltaStockLevelRow::from($row)->toDomain(),
            $response->results,
        );
    }
}
