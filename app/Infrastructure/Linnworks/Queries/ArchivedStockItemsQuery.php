<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Queries;

use App\Application\Linnworks\DTOs\ArchivedStockItemFlagsDTO;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Linnworks\Responses\SqlQueryResponse;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Row structure for ArchivedStockItemsQuery results.
 *
 * @internal Implementation detail of ArchivedStockItemsQuery
 */
final class ArchivedStockItemRow extends Data
{
    public function __construct(
        #[MapInputName('pkStockItemId')]
        public readonly string $stockItemId,
        #[MapInputName('IsArchived')]
        public readonly string $isArchived,
        #[MapInputName('bLogicalDelete')]
        public readonly string $isLogicallyDeleted,
    ) {}
}

/**
 * Query archived and logically-deleted stock items from Linnworks.
 *
 * Returns only flagged items (IsArchived = 1 OR bLogicalDelete = 1) for
 * targeted bulk updates — avoids fetching the full ~10k item catalogue.
 * Results are partitioned into separate ID lists for each flag.
 *
 * @extends AbstractLinnworksQuery<ArchivedStockItemFlagsDTO>
 *
 * @template-pattern Query Object
 */
final readonly class ArchivedStockItemsQuery extends AbstractLinnworksQuery
{
    protected function buildQueryBody(): string
    {
        return <<<'SQL'
            SELECT pkStockItemId, IsArchived, bLogicalDelete
            FROM StockItem
            WHERE IsArchived = 1 OR bLogicalDelete = 1
            SQL;
    }

    public function mapResponse(SqlQueryResponse $response): ArchivedStockItemFlagsDTO
    {
        return self::partitionRows($response->results);
    }

    /**
     * Partition raw result rows into separate archived / deleted ID lists.
     *
     * @param list<array<string, mixed>> $results
     */
    private static function partitionRows(array $results): ArchivedStockItemFlagsDTO
    {
        $archivedIds = [];
        $deletedIds = [];

        foreach ($results as $row) {
            $item = ArchivedStockItemRow::from($row);
            if ($item->isArchived === 'True') {
                $archivedIds[] = Guid::fromTrusted($item->stockItemId);
            }
            if ($item->isLogicallyDeleted === 'True') {
                $deletedIds[] = Guid::fromTrusted($item->stockItemId);
            }
        }

        return new ArchivedStockItemFlagsDTO(
            archivedIds: $archivedIds,
            deletedIds: $deletedIds,
        );
    }
}
