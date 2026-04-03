<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Linnworks\Requests;

use App\Domain\Catalog\Product\ValueObjects\Gtin;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Inventory\Commands\AddInventoryItemCommand;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\TaxRate;
use App\Infrastructure\Linnworks\Requests\AddInventoryItemRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(AddInventoryItemRequest::class)]
final class AddInventoryItemRequestTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | fromCommand — full field mapping
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_maps_all_fields_to_array_output(): void
    {
        $stockItemId = new Guid('550e8400-e29b-41d4-a716-446655440000');
        $categoryId = new Guid('6ba7b810-9dad-11d1-80b4-00c04fd430c8');
        $command = new AddInventoryItemCommand(
            sku: Sku::fromTrusted('SKU001'),
            title: 'Test Item',
            retailPrice: Money::inclusive(24.00),
            purchasePrice: Money::exclusive(10.00),
            taxRate: TaxRate::standard(),
            barcode: Gtin::fromTrusted('5060000000007'),
        );

        $result = AddInventoryItemRequest::fromCommand($stockItemId, $categoryId, $command)->toArray();

        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $result['StockItemId']);
        $this->assertSame('SKU001', $result['ItemNumber']);
        $this->assertSame('Test Item', $result['ItemTitle']);
        $this->assertSame('6ba7b810-9dad-11d1-80b4-00c04fd430c8', $result['CategoryId']);
        $this->assertSame('5060000000007', $result['BarcodeNumber']);
    }

    /*
    |--------------------------------------------------------------------------
    | fromCommand — tax rate mapping
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_maps_standard_tax_rate_to_negative_one(): void
    {
        $command = new AddInventoryItemCommand(
            sku: Sku::fromTrusted('SKU001'),
            title: 'Test Item',
            retailPrice: Money::inclusive(24.00),
            purchasePrice: Money::exclusive(10.00),
            taxRate: TaxRate::standard(),
        );

        $result = AddInventoryItemRequest::fromCommand(
            new Guid('550e8400-e29b-41d4-a716-446655440000'),
            new Guid('6ba7b810-9dad-11d1-80b4-00c04fd430c8'),
            $command,
        )->toArray();

        $this->assertSame(-1.0, $result['TaxRate']);
    }

    #[Test]
    public function it_maps_non_standard_tax_rate_to_actual_percentage(): void
    {
        $command = new AddInventoryItemCommand(
            sku: Sku::fromTrusted('SKU001'),
            title: 'Test Item',
            retailPrice: Money::inclusive(24.00),
            purchasePrice: Money::exclusive(10.00),
            taxRate: TaxRate::fromPercentage(5.0),
        );

        $result = AddInventoryItemRequest::fromCommand(
            new Guid('550e8400-e29b-41d4-a716-446655440000'),
            new Guid('6ba7b810-9dad-11d1-80b4-00c04fd430c8'),
            $command,
        )->toArray();

        $this->assertSame(5.0, $result['TaxRate']);
    }

    /*
    |--------------------------------------------------------------------------
    | fromCommand — null field defaults
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_defaults_null_purchase_price_to_zero(): void
    {
        $command = new AddInventoryItemCommand(
            sku: Sku::fromTrusted('SKU001'),
            title: 'Test Item',
            retailPrice: Money::inclusive(24.00),
            purchasePrice: null,
            taxRate: TaxRate::standard(),
        );

        $result = AddInventoryItemRequest::fromCommand(
            new Guid('550e8400-e29b-41d4-a716-446655440000'),
            new Guid('6ba7b810-9dad-11d1-80b4-00c04fd430c8'),
            $command,
        )->toArray();

        $this->assertSame(0.0, $result['PurchasePrice']);
    }

    #[Test]
    public function it_defaults_null_barcode_to_empty_string(): void
    {
        $command = new AddInventoryItemCommand(
            sku: Sku::fromTrusted('SKU001'),
            title: 'Test Item',
            retailPrice: Money::inclusive(24.00),
            purchasePrice: Money::exclusive(10.00),
            taxRate: TaxRate::standard(),
            barcode: null,
        );

        $result = AddInventoryItemRequest::fromCommand(
            new Guid('550e8400-e29b-41d4-a716-446655440000'),
            new Guid('6ba7b810-9dad-11d1-80b4-00c04fd430c8'),
            $command,
        )->toArray();

        $this->assertSame('', $result['BarcodeNumber']);
    }

    /*
    |--------------------------------------------------------------------------
    | fromCommand — price extraction
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_extracts_retail_price_as_gross(): void
    {
        $command = new AddInventoryItemCommand(
            sku: Sku::fromTrusted('SKU001'),
            title: 'Test Item',
            retailPrice: Money::inclusive(24.00),
            purchasePrice: Money::exclusive(10.00),
            taxRate: TaxRate::standard(),
        );

        $result = AddInventoryItemRequest::fromCommand(
            new Guid('550e8400-e29b-41d4-a716-446655440000'),
            new Guid('6ba7b810-9dad-11d1-80b4-00c04fd430c8'),
            $command,
        )->toArray();

        $this->assertSame(24.00, $result['RetailPrice']);
    }

    #[Test]
    public function it_extracts_purchase_price_as_net(): void
    {
        $command = new AddInventoryItemCommand(
            sku: Sku::fromTrusted('SKU001'),
            title: 'Test Item',
            retailPrice: Money::inclusive(24.00),
            purchasePrice: Money::exclusive(10.00),
            taxRate: TaxRate::standard(),
        );

        $result = AddInventoryItemRequest::fromCommand(
            new Guid('550e8400-e29b-41d4-a716-446655440000'),
            new Guid('6ba7b810-9dad-11d1-80b4-00c04fd430c8'),
            $command,
        )->toArray();

        $this->assertSame(10.00, $result['PurchasePrice']);
    }

    /*
    |--------------------------------------------------------------------------
    | toArray — API key names
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_uses_pascal_case_api_keys(): void
    {
        $command = new AddInventoryItemCommand(
            sku: Sku::fromTrusted('SKU001'),
            title: 'Test Item',
            retailPrice: Money::inclusive(24.00),
            purchasePrice: Money::exclusive(10.00),
            taxRate: TaxRate::standard(),
        );

        $result = AddInventoryItemRequest::fromCommand(
            new Guid('550e8400-e29b-41d4-a716-446655440000'),
            new Guid('6ba7b810-9dad-11d1-80b4-00c04fd430c8'),
            $command,
        )->toArray();

        $this->assertArrayHasKey('StockItemId', $result);
        $this->assertArrayHasKey('ItemNumber', $result);
        $this->assertArrayHasKey('ItemTitle', $result);
        $this->assertArrayHasKey('CategoryId', $result);
        $this->assertArrayHasKey('RetailPrice', $result);
        $this->assertArrayHasKey('PurchasePrice', $result);
        $this->assertArrayHasKey('TaxRate', $result);
        $this->assertArrayHasKey('BarcodeNumber', $result);
    }
}
