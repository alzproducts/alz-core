<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Linnworks\Queries;

use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Linnworks\Queries\OpenOrderIdsQuery;
use App\Infrastructure\Linnworks\Responses\SqlQueryResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for OpenOrderIdsQuery.
 *
 * Tests SQL generation and response mapping.
 */
#[CoversClass(OpenOrderIdsQuery::class)]
final class OpenOrderIdsQueryTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | buildSql
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_builds_sql_with_isolation_level_prefix(): void
    {
        $query = new OpenOrderIdsQuery();

        $sql = $query->buildSql();

        $this->assertStringStartsWith('SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED;', $sql);
    }

    #[Test]
    public function it_selects_from_open_order_view(): void
    {
        $query = new OpenOrderIdsQuery();

        $sql = $query->buildSql();

        $this->assertStringContainsString('SELECT pkOrderID FROM [Open_Order]', $sql);
    }

    /*
    |--------------------------------------------------------------------------
    | mapResponse
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_maps_response_to_list_of_guids(): void
    {
        $query = new OpenOrderIdsQuery();

        $response = new SqlQueryResponse(
            isError: false,
            totalResults: 2,
            columns: [],
            results: [
                ['pkOrderID' => '550e8400-e29b-41d4-a716-446655440000'],
                ['pkOrderID' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8'],
            ],
        );

        $result = $query->mapResponse($response);

        $this->assertCount(2, $result);
        $this->assertInstanceOf(Guid::class, $result[0]);
        $this->assertInstanceOf(Guid::class, $result[1]);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $result[0]->value);
        $this->assertSame('6ba7b810-9dad-11d1-80b4-00c04fd430c8', $result[1]->value);
    }

    #[Test]
    public function it_returns_empty_array_for_empty_results(): void
    {
        $query = new OpenOrderIdsQuery();

        $response = new SqlQueryResponse(
            isError: false,
            totalResults: 0,
            columns: [],
            results: [],
        );

        $result = $query->mapResponse($response);

        $this->assertSame([], $result);
    }
}
