<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Linnworks\Queries;

use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Linnworks\Queries\StockItemBySkuQuery;
use App\Infrastructure\Linnworks\Responses\SqlQueryResponse;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for StockItemBySkuQuery.
 *
 * Tests SQL generation and response mapping.
 */
#[CoversClass(StockItemBySkuQuery::class)]
final class StockItemBySkuQueryTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | buildSql
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_builds_sql_with_isolation_level_prefix(): void
    {
        $query = new StockItemBySkuQuery(['SKU001']);

        $sql = $query->buildSql();

        $this->assertStringStartsWith('SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED;', $sql);
    }

    #[Test]
    public function it_builds_correct_select_query(): void
    {
        $query = new StockItemBySkuQuery(['SKU001', 'SKU002']);

        $sql = $query->buildSql();

        $this->assertStringContainsString(
            "SELECT pkStockItemID, ItemNumber FROM StockItem WHERE ItemNumber IN ('SKU001', 'SKU002')",
            $sql,
        );
    }

    #[Test]
    public function it_escapes_special_characters_in_skus(): void
    {
        $query = new StockItemBySkuQuery(["O'Reilly"]);

        $sql = $query->buildSql();

        $this->assertStringContainsString("('O''Reilly')", $sql);
    }

    #[Test]
    public function it_throws_on_empty_sku_array(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SKU list cannot be empty');

        $query = new StockItemBySkuQuery([]);
        $query->buildSql();
    }

    /*
    |--------------------------------------------------------------------------
    | mapResponse
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_maps_response_to_sku_guid_array(): void
    {
        $query = new StockItemBySkuQuery(['SKU001', 'SKU002']);

        $response = new SqlQueryResponse(
            isError: false,
            totalResults: 2,
            columns: [],
            results: [
                [
                    'pkStockItemID' => '550e8400-e29b-41d4-a716-446655440000',
                    'ItemNumber' => 'SKU001',
                ],
                [
                    'pkStockItemID' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
                    'ItemNumber' => 'SKU002',
                ],
            ],
        );

        $result = $query->mapResponse($response);

        $this->assertCount(2, $result);
        $this->assertArrayHasKey('SKU001', $result);
        $this->assertArrayHasKey('SKU002', $result);
        $this->assertInstanceOf(Guid::class, $result['SKU001']);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $result['SKU001']->value);
        $this->assertSame('6ba7b810-9dad-11d1-80b4-00c04fd430c8', $result['SKU002']->value);
    }

    #[Test]
    public function it_returns_empty_array_for_empty_results(): void
    {
        $query = new StockItemBySkuQuery(['NONEXISTENT']);

        $response = new SqlQueryResponse(
            isError: false,
            totalResults: 0,
            columns: [],
            results: [],
        );

        $result = $query->mapResponse($response);

        $this->assertSame([], $result);
    }

    #[Test]
    public function it_returns_only_found_skus(): void
    {
        $query = new StockItemBySkuQuery(['FOUND', 'NOTFOUND']);

        $response = new SqlQueryResponse(
            isError: false,
            totalResults: 1,
            columns: [],
            results: [
                [
                    'pkStockItemID' => '550e8400-e29b-41d4-a716-446655440000',
                    'ItemNumber' => 'FOUND',
                ],
            ],
        );

        $result = $query->mapResponse($response);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('FOUND', $result);
        $this->assertArrayNotHasKey('NOTFOUND', $result);
    }
}
