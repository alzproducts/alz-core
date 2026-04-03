<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Linnworks\Requests;

use App\Domain\Inventory\Enums\ExtendedPropertyName;
use App\Domain\Inventory\ValueObjects\ExtendedPropertyWrite;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Linnworks\Requests\ExtendedPropertyRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(ExtendedPropertyRequest::class)]
final class ExtendedPropertyRequestTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | fromWrite — without rowId
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_maps_property_and_stock_item_id_without_row_id(): void
    {
        $stockItemId = new Guid('550e8400-e29b-41d4-a716-446655440000');
        $property = ExtendedPropertyWrite::create(ExtendedPropertyName::ShopId, 'some-value');

        $result = ExtendedPropertyRequest::fromWrite($property, $stockItemId)->toArray();

        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $result['fkStockItemId']);
        $this->assertSame('ShopID', $result['ProperyName']);
        $this->assertSame('some-value', $result['PropertyValue']);
        $this->assertSame('Attribute', $result['PropertyType']);
    }

    #[Test]
    public function it_produces_four_keys_without_row_id(): void
    {
        $stockItemId = new Guid('550e8400-e29b-41d4-a716-446655440000');
        $property = ExtendedPropertyWrite::create(ExtendedPropertyName::ShopId, 'some-value');

        $result = ExtendedPropertyRequest::fromWrite($property, $stockItemId)->toArray();

        $this->assertCount(4, $result);
        $this->assertArrayNotHasKey('pkRowId', $result);
    }

    /*
    |--------------------------------------------------------------------------
    | fromWrite — with rowId
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_includes_row_id_when_provided(): void
    {
        $stockItemId = new Guid('550e8400-e29b-41d4-a716-446655440000');
        $property = ExtendedPropertyWrite::create(ExtendedPropertyName::ShopId, 'some-value');

        $result = ExtendedPropertyRequest::fromWrite($property, $stockItemId, 'row-123')->toArray();

        $this->assertSame('row-123', $result['pkRowId']);
    }

    #[Test]
    public function it_produces_five_keys_with_row_id(): void
    {
        $stockItemId = new Guid('550e8400-e29b-41d4-a716-446655440000');
        $property = ExtendedPropertyWrite::create(ExtendedPropertyName::ShopId, 'some-value');

        $result = ExtendedPropertyRequest::fromWrite($property, $stockItemId, 'row-123')->toArray();

        $this->assertCount(5, $result);
    }

    /*
    |--------------------------------------------------------------------------
    | toArray — intentional "ProperyName" typo
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_uses_intentionally_misspelled_propery_name_key(): void
    {
        $stockItemId = new Guid('550e8400-e29b-41d4-a716-446655440000');
        $property = ExtendedPropertyWrite::create(ExtendedPropertyName::SellingPriceGross, '99.99');

        $result = ExtendedPropertyRequest::fromWrite($property, $stockItemId)->toArray();

        // "ProperyName" (missing 't') is intentional — Linnworks API expects this misspelling
        $this->assertArrayHasKey('ProperyName', $result);
        $this->assertArrayNotHasKey('PropertyName', $result);
        $this->assertSame('SellingPriceGross', $result['ProperyName']);
    }

    /*
    |--------------------------------------------------------------------------
    | toArray — API key names
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_uses_correct_api_keys(): void
    {
        $stockItemId = new Guid('550e8400-e29b-41d4-a716-446655440000');
        $property = ExtendedPropertyWrite::create(ExtendedPropertyName::ShopId, 'some-value');

        $result = ExtendedPropertyRequest::fromWrite($property, $stockItemId)->toArray();

        $this->assertArrayHasKey('fkStockItemId', $result);
        $this->assertArrayHasKey('ProperyName', $result);
        $this->assertArrayHasKey('PropertyValue', $result);
        $this->assertArrayHasKey('PropertyType', $result);
    }
}
