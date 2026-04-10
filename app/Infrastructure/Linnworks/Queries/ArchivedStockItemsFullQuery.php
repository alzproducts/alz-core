<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Queries;

use App\Application\Linnworks\DTOs\ArchivedStockItemDTO;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Inventory\Enums\WeightUnit;
use App\Domain\Inventory\ValueObjects\Dimensions;
use App\Domain\Inventory\ValueObjects\StockItemFull;
use App\Domain\Inventory\ValueObjects\Weight;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Linnworks\Responses\SqlQueryResponse;
use App\Infrastructure\Linnworks\Support\LinnworksDateParser;
use Spatie\LaravelData\Data;

/**
 * Row structure for ArchivedStockItemsFullQuery results.
 *
 * Properties are named to match the SQL column names so Spatie LaravelData
 * can auto-map them without explicit `#[MapInputName]` attributes. Every
 * column arrives as a string — the SQL Dashboards endpoint hands back raw
 * text regardless of underlying column type — so all casts happen explicitly
 * in the Query's `toDomain()` helper.
 *
 * @internal Implementation detail of ArchivedStockItemsFullQuery
 */
final class ArchivedStockItemFullRow extends Data
{
    public function __construct(
        public readonly string $pkStockItemID,
        public readonly string $ItemNumber,
        public readonly string $ItemTitle,
        public readonly ?string $BarcodeNumber,
        public readonly string $PurchasePrice,
        public readonly string $RetailPrice,
        public readonly string $TaxRate,
        public readonly string $Weight,
        public readonly string $DimHeight,
        public readonly string $DimWidth,
        public readonly string $DimDepth,
        public readonly string $bContainsComposites,
        public readonly string $CategoryId,
        public readonly ?string $CategoryName,
        public readonly ?string $CreationDate,
        public readonly string $IsArchived,
        public readonly string $bLogicalDelete,
    ) {}
}

/**
 * Query archived stock items with full field data.
 *
 * Uses the SQL Dashboards endpoint because the Linnworks Inventory REST API
 * silently filters archived items out of every relevant endpoint
 * (`GetStockItemsFull`, `GetInventoryItemById`, `GetStockItemsFullByIds`).
 * Joins `ProductCategories` for category metadata and excludes rows with
 * empty `ItemNumber` (no SKU = no use locally).
 *
 * Only rows where `IsArchived = 1` are returned. `bLogicalDelete` is
 * deliberately NOT part of the filter: in practice it tags stale
 * Linnworks-internal history rows (superseded GUIDs for re-created SKUs)
 * rather than the user-facing "deleted" state, so including it would
 * import ghost rows that collide with live items on `ItemNumber`.
 *
 * Archived rows whose `ItemNumber` is shared with ANY other row in the
 * `StockItem` table (active, archived, or ghost) are excluded via
 * `NOT EXISTS`. Rationale: if the SKU is ambiguous upstream, the daily
 * active-stock-item sync will pick up the live row anyway, and we don't
 * want duplicate `item_number`s in `linnworks.stock_items` tripping up
 * downstream SKU→ID resolution.
 *
 * Returns rows wrapped in {@see ArchivedStockItemDTO} so the archive flags
 * can flow to the repository without mutating the shared {@see StockItemFull}
 * domain VO.
 *
 * @extends AbstractLinnworksQuery<list<ArchivedStockItemDTO>>
 *
 * @template-pattern Query Object
 */
final readonly class ArchivedStockItemsFullQuery extends AbstractLinnworksQuery
{
    protected function buildQueryBody(): string
    {
        return <<<'SQL'
            SELECT
                s.pkStockItemID, s.ItemNumber, s.ItemTitle, s.BarcodeNumber, s.PurchasePrice, s.RetailPrice, s.TaxRate,
                s.Weight, s.DimHeight, s.DimWidth, s.DimDepth, s.bContainsComposites, s.CategoryId, c.CategoryName,
                s.CreationDate, s.IsArchived, s.bLogicalDelete
            FROM [StockItem] s
            LEFT JOIN [ProductCategories] c ON c.CategoryId = s.CategoryId
            WHERE s.IsArchived = 1 AND s.ItemNumber IS NOT NULL AND s.ItemNumber <> ''
              AND NOT EXISTS (
                SELECT 1 FROM [StockItem] s2
                WHERE s2.ItemNumber = s.ItemNumber AND s2.pkStockItemID <> s.pkStockItemID
              )
            ORDER BY s.pkStockItemID
            SQL;
    }

    /**
     * @return list<ArchivedStockItemDTO>
     *
     * @throws InvalidApiResponseException When a row's CreationDate is malformed
     */
    public function mapResponse(SqlQueryResponse $response): array
    {
        return \array_map(
            fn(array $row): ArchivedStockItemDTO => $this->toDomain(ArchivedStockItemFullRow::from($row)),
            $response->results,
        );
    }

    /**
     * Convert a raw row into an application DTO carrying a domain VO.
     *
     * Zero-fills all stock-level fields — archived items have no live stock
     * by definition (that's a semantic truth, not a placeholder). Negative
     * tax rates collapse to null (Linnworks sentinel for "use default"),
     * matching the convention in other stock item responses.
     *
     * @throws InvalidApiResponseException When CreationDate is malformed
     */
    private function toDomain(ArchivedStockItemFullRow $row): ArchivedStockItemDTO
    {
        $taxRate = (float) $row->TaxRate;

        $item = new StockItemFull(
            stockItemId: Guid::fromTrusted($row->pkStockItemID)->value,
            sku: $row->ItemNumber,
            title: $row->ItemTitle,
            barcode: $row->BarcodeNumber ?? '',
            quantity: 0,
            available: 0,
            inOrder: 0,
            due: 0,
            minimumLevel: 0,
            jit: false,
            purchasePrice: (float) $row->PurchasePrice,
            retailPrice: (float) $row->RetailPrice,
            taxRate: $taxRate < 0 ? null : $taxRate,
            weight: new Weight(\max(0.0, (float) $row->Weight), WeightUnit::Kilogram),
            dimensions: new Dimensions(
                \max(0.0, (float) $row->DimHeight),
                \max(0.0, (float) $row->DimWidth),
                \max(0.0, (float) $row->DimDepth),
            ),
            isComposite: $row->bContainsComposites === 'True',
            categoryId: $row->CategoryId,
            categoryName: $row->CategoryName ?? 'Default',
            createdAt: LinnworksDateParser::parse($row->CreationDate),
        );

        return new ArchivedStockItemDTO(
            item: $item,
            isArchived: $row->IsArchived === 'True',
            isLogicallyDeleted: $row->bLogicalDelete === 'True',
        );
    }
}
