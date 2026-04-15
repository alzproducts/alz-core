<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Queries;

use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Linnworks\Responses\SqlQueryResponse;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Row structure for CompositeStockItemFlagsQuery results.
 *
 * @internal Implementation detail of CompositeStockItemFlagsQuery
 */
final class CompositeStockItemFlagRow extends Data
{
    public function __construct(
        #[MapInputName('pkStockItemID')]
        public readonly string $stockItemId,
    ) {}
}

/**
 * Query active stock items that are composite parents.
 *
 * Uses the SQL Dashboards endpoint because `GetStockItemsFull` (bulk sync)
 * does not return the `IsCompositeParent` field. Only `GetStockItemsFullByIds`
 * (per-product refresh) returns it, but that's too slow for bulk backfill.
 *
 * Returns GUIDs of all active items where `bContainsComposites = 'True'`.
 * The caller uses a two-pass update: set `is_composite = true` for returned
 * IDs, then reset to `false` for any rows previously flagged but not returned.
 *
 * @extends AbstractLinnworksQuery<list<Guid>>
 *
 * @template-pattern Query Object
 */
final readonly class CompositeStockItemFlagsQuery extends AbstractLinnworksQuery
{
    protected function buildQueryBody(): string
    {
        return <<<'SQL'
            SELECT s.pkStockItemID
            FROM [StockItem] s
            WHERE s.IsArchived = 0
              AND s.ItemNumber IS NOT NULL
              AND s.ItemNumber <> ''
              AND s.bContainsComposites = 1
            ORDER BY s.pkStockItemID
            SQL;
    }

    /**
     * @return list<Guid>
     */
    public function mapResponse(SqlQueryResponse $response): array
    {
        return \array_map(
            static fn(array $row): Guid => Guid::fromTrusted(
                CompositeStockItemFlagRow::from($row)->stockItemId,
            ),
            $response->results,
        );
    }
}
