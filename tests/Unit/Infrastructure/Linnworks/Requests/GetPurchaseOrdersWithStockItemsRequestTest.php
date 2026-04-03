<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Linnworks\Requests;

use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Linnworks\Requests\GetPurchaseOrdersWithStockItemsRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(GetPurchaseOrdersWithStockItemsRequest::class)]
final class GetPurchaseOrdersWithStockItemsRequestTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | fromResolved — field mapping
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_maps_stock_item_id_and_location_ids_to_array_output(): void
    {
        $stockItemId = new Guid('550e8400-e29b-41d4-a716-446655440000');
        $locationIds = [
            new Guid('6ba7b810-9dad-11d1-80b4-00c04fd430c8'),
            new Guid('7c9e6679-7425-40de-944b-e07fc1f90ae7'),
        ];

        $result = GetPurchaseOrdersWithStockItemsRequest::fromResolved($stockItemId, $locationIds)->toArray();

        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $result['StockItemId']);
        $this->assertSame([
            '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
            '7c9e6679-7425-40de-944b-e07fc1f90ae7',
        ], $result['LocationIds']);
    }

    /*
    |--------------------------------------------------------------------------
    | fromResolved — Guid objects converted to strings
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_converts_guid_location_ids_to_string_values(): void
    {
        $stockItemId = new Guid('550e8400-e29b-41d4-a716-446655440000');
        $locationGuid = new Guid('6ba7b810-9dad-11d1-80b4-00c04fd430c8');

        $result = GetPurchaseOrdersWithStockItemsRequest::fromResolved($stockItemId, [$locationGuid])->toArray();

        $locationIds = $result['LocationIds'];
        $this->assertIsArray($locationIds);
        $this->assertIsString($locationIds[0]);
        $this->assertSame('6ba7b810-9dad-11d1-80b4-00c04fd430c8', $locationIds[0]);
    }

    /*
    |--------------------------------------------------------------------------
    | fromResolved — empty locationIds
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_handles_empty_location_ids_array(): void
    {
        $stockItemId = new Guid('550e8400-e29b-41d4-a716-446655440000');

        $result = GetPurchaseOrdersWithStockItemsRequest::fromResolved($stockItemId, [])->toArray();

        $this->assertSame([], $result['LocationIds']);
    }

    /*
    |--------------------------------------------------------------------------
    | fromResolved — multiple location IDs
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_maps_multiple_location_ids_in_order(): void
    {
        $stockItemId = new Guid('550e8400-e29b-41d4-a716-446655440000');
        $locationIds = [
            new Guid('6ba7b810-9dad-11d1-80b4-00c04fd430c8'),
            new Guid('7c9e6679-7425-40de-944b-e07fc1f90ae7'),
            new Guid('886313e1-3b8a-5372-9b90-0c9aee199e5d'),
        ];

        $result = GetPurchaseOrdersWithStockItemsRequest::fromResolved($stockItemId, $locationIds)->toArray();

        $this->assertCount(3, $result['LocationIds']);
        $this->assertSame('6ba7b810-9dad-11d1-80b4-00c04fd430c8', $result['LocationIds'][0]);
        $this->assertSame('7c9e6679-7425-40de-944b-e07fc1f90ae7', $result['LocationIds'][1]);
        $this->assertSame('886313e1-3b8a-5372-9b90-0c9aee199e5d', $result['LocationIds'][2]);
    }

    /*
    |--------------------------------------------------------------------------
    | toArray — API key names
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_uses_correct_api_keys(): void
    {
        $result = GetPurchaseOrdersWithStockItemsRequest::fromResolved(
            new Guid('550e8400-e29b-41d4-a716-446655440000'),
            [],
        )->toArray();

        $this->assertArrayHasKey('StockItemId', $result);
        $this->assertArrayHasKey('LocationIds', $result);
    }
}
