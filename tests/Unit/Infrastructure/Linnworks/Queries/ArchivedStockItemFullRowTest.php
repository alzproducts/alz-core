<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Linnworks\Queries;

use App\Application\Linnworks\DTOs\ArchivedStockItemDTO;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Inventory\Enums\WeightUnit;
use App\Infrastructure\Linnworks\Queries\ArchivedStockItemFullRow;
use App\Infrastructure\Linnworks\Queries\ArchivedStockItemsFullQuery;
use App\Infrastructure\Linnworks\Responses\SqlQueryResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for ArchivedStockItemsFullQuery and its co-located row DTO.
 *
 * The co-located {@see ArchivedStockItemFullRow}
 * is not PSR-4 autoloadable on its own, so every assertion is driven through
 * {@see ArchivedStockItemsFullQuery::mapResponse()}. That's also the exact path
 * the production code takes, so mutation testing will catch any cast that
 * silently drifts from the wire format Linnworks hands us back.
 */
#[CoversClass(ArchivedStockItemsFullQuery::class)]
final class ArchivedStockItemFullRowTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Row DTO: happy path
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_casts_a_fully_populated_archived_row_into_a_domain_dto(): void
    {
        $dto = $this->mapRow($this->validRow());

        $this->assertInstanceOf(ArchivedStockItemDTO::class, $dto);
        $this->assertTrue($dto->isArchived);
        $this->assertFalse($dto->isLogicallyDeleted);

        $item = $dto->item;
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $item->stockItemId);
        $this->assertSame('100376', $item->sku);
        $this->assertSame('Vintage sign — archived', $item->title);
        $this->assertSame('5012345678900', $item->barcode);
        $this->assertSame(12.5, $item->purchasePrice);
        $this->assertSame(29.99, $item->retailPrice);
        $this->assertSame(20.0, $item->taxRate);
        $this->assertSame(1.25, $item->weight->value);
        $this->assertSame(WeightUnit::Kilogram, $item->weight->unit);
        $this->assertSame(10.0, $item->dimensions->height);
        $this->assertSame(15.0, $item->dimensions->width);
        $this->assertSame(5.0, $item->dimensions->depth);
        $this->assertFalse($item->isComposite);
        $this->assertSame('11111111-2222-3333-4444-555555555555', $item->categoryId);
        $this->assertSame('Signs', $item->categoryName);
        $this->assertNotNull($item->createdAt);
        $this->assertSame('2024-01-15', $item->createdAt->format('Y-m-d'));
    }

    #[Test]
    public function it_zero_fills_every_stock_level_for_archived_items(): void
    {
        $item = $this->mapRow($this->validRow())->item;

        // Archived items have no live stock — zero is the semantic truth
        $this->assertSame(0, $item->quantity);
        $this->assertSame(0, $item->available);
        $this->assertSame(0, $item->inOrder);
        $this->assertSame(0, $item->due);
        $this->assertSame(0, $item->minimumLevel);
        $this->assertFalse($item->jit);
        $this->assertSame([], $item->extendedProperties);
        $this->assertSame([], $item->suppliers);
    }

    /*
    |--------------------------------------------------------------------------
    | Row DTO: edge cases
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_collapses_negative_tax_rate_to_null_matching_linnworks_sentinel(): void
    {
        $dto = $this->mapRow([...$this->validRow(), 'TaxRate' => '-1']);

        $this->assertNull($dto->item->taxRate);
    }

    #[Test]
    public function it_keeps_zero_tax_rate_as_zero_not_null(): void
    {
        $dto = $this->mapRow([...$this->validRow(), 'TaxRate' => '0']);

        // Zero is a real tax rate (zero-rated goods), distinct from "-1" sentinel
        $this->assertSame(0.0, $dto->item->taxRate);
    }

    #[Test]
    public function it_flips_composite_flag_when_contains_composites_is_true(): void
    {
        $dto = $this->mapRow([...$this->validRow(), 'bContainsComposites' => 'True']);

        $this->assertTrue($dto->item->isComposite);
    }

    #[Test]
    public function it_falls_back_to_default_category_name_when_left_join_returned_null(): void
    {
        $dto = $this->mapRow([...$this->validRow(), 'CategoryName' => null]);

        // Matches the `category_name` column default set in the migration.
        $this->assertSame('Default', $dto->item->categoryName);
    }

    #[Test]
    public function it_treats_missing_barcode_as_empty_string(): void
    {
        $dto = $this->mapRow([...$this->validRow(), 'BarcodeNumber' => null]);

        $this->assertSame('', $dto->item->barcode);
    }

    #[Test]
    public function it_returns_null_created_at_for_linnworks_sentinel_date(): void
    {
        $dto = $this->mapRow([
            ...$this->validRow(),
            'CreationDate' => '0001-01-01T00:00:00',
        ]);

        $this->assertNull($dto->item->createdAt);
    }

    #[Test]
    public function it_clamps_negative_weight_and_dimensions_to_zero(): void
    {
        $item = $this->mapRow([
            ...$this->validRow(),
            'Weight' => '-2.5',
            'DimHeight' => '-1',
            'DimWidth' => '-0.5',
            'DimDepth' => '-10',
        ])->item;

        $this->assertSame(0.0, $item->weight->value);
        $this->assertSame(0.0, $item->dimensions->height);
        $this->assertSame(0.0, $item->dimensions->width);
        $this->assertSame(0.0, $item->dimensions->depth);
    }

    /*
    |--------------------------------------------------------------------------
    | Query: SQL + wiring
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function query_builds_sql_with_read_uncommitted_isolation_level(): void
    {
        $sql = (new ArchivedStockItemsFullQuery())->buildSql();

        $this->assertStringStartsWith('SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED;', $sql);
    }

    #[Test]
    public function query_filters_by_archive_flag_and_excludes_empty_skus(): void
    {
        $sql = (new ArchivedStockItemsFullQuery())->buildSql();

        $this->assertStringContainsString('FROM [StockItem] s', $sql);
        $this->assertStringContainsString('LEFT JOIN [ProductCategories] c ON c.CategoryId = s.CategoryId', $sql);
        $this->assertStringContainsString('s.IsArchived = 1', $sql);
        $this->assertStringNotContainsString('bLogicalDelete = 1', $sql);
        $this->assertStringContainsString('s.ItemNumber IS NOT NULL', $sql);
        $this->assertStringContainsString("s.ItemNumber <> ''", $sql);
        $this->assertStringContainsString('ORDER BY s.pkStockItemID', $sql);
    }

    #[Test]
    public function query_excludes_item_numbers_that_appear_on_any_other_stockitem_row(): void
    {
        $sql = (new ArchivedStockItemsFullQuery())->buildSql();

        // NOT EXISTS sibling guard: drop archived rows whose ItemNumber collides
        // with any other row in StockItem. Ambiguous SKUs are left to the daily
        // active-stock-item sync to handle.
        $this->assertStringContainsString('NOT EXISTS', $sql);
        $this->assertStringContainsString('FROM [StockItem] s2', $sql);
        $this->assertStringContainsString('s2.ItemNumber = s.ItemNumber', $sql);
        $this->assertStringContainsString('s2.pkStockItemID <> s.pkStockItemID', $sql);
    }

    #[Test]
    public function map_response_returns_one_dto_per_row(): void
    {
        $query = new ArchivedStockItemsFullQuery();

        $response = new SqlQueryResponse(
            isError: false,
            totalResults: 2,
            columns: [],
            results: [
                $this->validRow(),
                [
                    ...$this->validRow(),
                    'pkStockItemID' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
                    'ItemNumber' => '100377',
                ],
            ],
        );

        $dtos = $query->mapResponse($response);

        $this->assertCount(2, $dtos);
        $this->assertTrue($dtos[0]->isArchived);
        $this->assertTrue($dtos[1]->isArchived);
        $this->assertSame('100376', $dtos[0]->item->sku);
        $this->assertSame('100377', $dtos[1]->item->sku);
    }

    #[Test]
    public function map_response_returns_empty_list_when_no_rows(): void
    {
        $query = new ArchivedStockItemsFullQuery();

        $response = new SqlQueryResponse(
            isError: false,
            totalResults: 0,
            columns: [],
            results: [],
        );

        $this->assertSame([], $query->mapResponse($response));
    }

    /**
     * Drive a single row through the real production code path and return the
     * mapped DTO. Using the query's own `mapResponse` means the co-located row
     * class gets autoloaded via the query file, sidestepping PSR-4's
     * one-class-per-file assumption.
     *
     * @param  array<string, mixed>  $row
     *
     * @throws InvalidApiResponseException When the row's CreationDate is malformed
     */
    private function mapRow(array $row): ArchivedStockItemDTO
    {
        $dtos = (new ArchivedStockItemsFullQuery())->mapResponse(new SqlQueryResponse(
            isError: false,
            totalResults: 1,
            columns: [],
            results: [$row],
        ));

        return $dtos[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function validRow(): array
    {
        return [
            'pkStockItemID' => '550e8400-e29b-41d4-a716-446655440000',
            'ItemNumber' => '100376',
            'ItemTitle' => 'Vintage sign — archived',
            'BarcodeNumber' => '5012345678900',
            'PurchasePrice' => '12.5',
            'RetailPrice' => '29.99',
            'TaxRate' => '20',
            'Weight' => '1.25',
            'DimHeight' => '10',
            'DimWidth' => '15',
            'DimDepth' => '5',
            'bContainsComposites' => 'False',
            'CategoryId' => '11111111-2222-3333-4444-555555555555',
            'CategoryName' => 'Signs',
            'CreationDate' => '2024-01-15T09:30:00',
            'IsArchived' => 'True',
            'bLogicalDelete' => 'False',
        ];
    }
}
