<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Linnworks\Responses;

use App\Domain\Inventory\ValueObjects\StockItem as DomainStockItem;
use App\Infrastructure\Linnworks\Responses\StockItem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * StockItem Response DTO Unit Tests.
 *
 * Tests the Spatie Data DTO for Linnworks stock items:
 * - PascalCase input mapping from API response
 * - Domain conversion (toDomain())
 * - Nullable field handling (weight, isCompositeParent)
 */
#[CoversClass(StockItem::class)]
final class StockItemTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | API Response Parsing Tests (PascalCase → camelCase)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_parses_api_response_with_pascal_case_keys(): void
    {
        $dto = StockItem::from(self::validApiResponse());

        $this->assertSame('guid-stock-item-id', $dto->stockItemId);
        $this->assertSame(12345, $dto->stockItemIntId);
        $this->assertSame('TEST-SKU-001', $dto->itemNumber);
        $this->assertSame('Test Product', $dto->itemTitle);
        $this->assertSame('1234567890123', $dto->barcodeNumber);
        $this->assertSame(100, $dto->quantity);
        $this->assertSame(5, $dto->inOrder);
        $this->assertSame(10, $dto->due);
        $this->assertSame(95, $dto->available);
        $this->assertSame(20, $dto->minimumLevel);
        $this->assertSame(25.50, $dto->purchasePrice);
        $this->assertSame(49.99, $dto->retailPrice);
        $this->assertSame(20.0, $dto->taxRate);
        $this->assertSame(1.5, $dto->weight);
        $this->assertSame(10.0, $dto->height);
        $this->assertSame(5.0, $dto->width);
        $this->assertSame(2.0, $dto->depth);
        $this->assertSame('category-guid', $dto->categoryId);
        $this->assertFalse($dto->isCompositeParent);
        $this->assertFalse($dto->isBatchedStockType);
        $this->assertSame(1, $dto->inventoryTrackingType);
    }

    #[Test]
    public function it_handles_null_weight(): void
    {
        $data = self::validApiResponse();
        $data['Weight'] = null;

        $dto = StockItem::from($data);

        $this->assertNull($dto->weight);
    }

    #[Test]
    public function it_handles_null_is_composite_parent(): void
    {
        $data = self::validApiResponse();
        $data['IsCompositeParent'] = null;

        $dto = StockItem::from($data);

        $this->assertNull($dto->isCompositeParent);
    }

    /*
    |--------------------------------------------------------------------------
    | Domain Conversion Tests (toDomain())
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_converts_to_domain_stock_item(): void
    {
        $dto = StockItem::from(self::validApiResponse());

        $domain = $dto->toDomain();

        $this->assertInstanceOf(DomainStockItem::class, $domain);
    }

    #[Test]
    public function it_maps_item_number_to_domain_sku(): void
    {
        $dto = StockItem::from(self::validApiResponse());

        $domain = $dto->toDomain();

        $this->assertSame('TEST-SKU-001', $domain->sku);
    }

    #[Test]
    public function it_maps_item_title_to_domain_title(): void
    {
        $dto = StockItem::from(self::validApiResponse());

        $domain = $dto->toDomain();

        $this->assertSame('Test Product', $domain->title);
    }

    #[Test]
    public function it_sets_description_to_null_in_domain(): void
    {
        // The GetInventoryItemById endpoint doesn't return description
        $dto = StockItem::from(self::validApiResponse());

        $domain = $dto->toDomain();

        $this->assertNull($domain->description);
    }

    #[Test]
    public function it_maps_all_inventory_quantities(): void
    {
        $dto = StockItem::from(self::validApiResponse());

        $domain = $dto->toDomain();

        $this->assertSame(100, $domain->quantity);
        $this->assertSame(95, $domain->available);
        $this->assertSame(5, $domain->inOrder);
        $this->assertSame(10, $domain->due);
        $this->assertSame(20, $domain->minimumLevel);
    }

    #[Test]
    public function it_maps_all_pricing_fields(): void
    {
        $dto = StockItem::from(self::validApiResponse());

        $domain = $dto->toDomain();

        $this->assertSame(25.50, $domain->purchasePrice);
        $this->assertSame(49.99, $domain->retailPrice);
        $this->assertSame(20.0, $domain->taxRate);
    }

    #[Test]
    public function it_maps_all_dimension_fields(): void
    {
        $dto = StockItem::from(self::validApiResponse());

        $domain = $dto->toDomain();

        $this->assertSame(1.5, $domain->weight);
        $this->assertSame(10.0, $domain->height);
        $this->assertSame(5.0, $domain->width);
        $this->assertSame(2.0, $domain->depth);
    }

    #[Test]
    public function it_maps_is_composite_parent_true_to_domain(): void
    {
        $data = self::validApiResponse();
        $data['IsCompositeParent'] = true;

        $dto = StockItem::from($data);
        $domain = $dto->toDomain();

        $this->assertTrue($domain->isComposite);
    }

    #[Test]
    public function it_maps_is_composite_parent_false_to_domain(): void
    {
        $data = self::validApiResponse();
        $data['IsCompositeParent'] = false;

        $dto = StockItem::from($data);
        $domain = $dto->toDomain();

        $this->assertFalse($domain->isComposite);
    }

    #[Test]
    public function it_maps_is_composite_parent_null_to_false_in_domain(): void
    {
        $data = self::validApiResponse();
        $data['IsCompositeParent'] = null;

        $dto = StockItem::from($data);
        $domain = $dto->toDomain();

        $this->assertFalse($domain->isComposite);
    }

    #[Test]
    public function it_preserves_null_weight_in_domain(): void
    {
        $data = self::validApiResponse();
        $data['Weight'] = null;

        $dto = StockItem::from($data);
        $domain = $dto->toDomain();

        $this->assertNull($domain->weight);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    private static function validApiResponse(): array
    {
        return [
            'StockItemId' => 'guid-stock-item-id',
            'StockItemIntId' => 12345,
            'ItemNumber' => 'TEST-SKU-001',
            'ItemTitle' => 'Test Product',
            'BarcodeNumber' => '1234567890123',
            'Quantity' => 100,
            'InOrder' => 5,
            'Due' => 10,
            'Available' => 95,
            'MinimumLevel' => 20,
            'PurchasePrice' => 25.50,
            'RetailPrice' => 49.99,
            'TaxRate' => 20.0,
            'Weight' => 1.5,
            'Height' => 10.0,
            'Width' => 5.0,
            'Depth' => 2.0,
            'CategoryId' => 'category-guid',
            'IsCompositeParent' => false,
            'IsBatchedStockType' => false,
            'InventoryTrackingType' => 1,
        ];
    }
}
