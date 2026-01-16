<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Inventory\ValueObjects;

use App\Domain\Inventory\Enums\WeightUnit;
use App\Domain\Inventory\ValueObjects\Dimensions;
use App\Domain\Inventory\ValueObjects\StockItem;
use App\Domain\Inventory\ValueObjects\StockItemExtendedProperty;
use App\Domain\Inventory\ValueObjects\Weight;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * StockItem Value Object Unit Tests.
 *
 * Tests the Domain value object for vendor-agnostic stock items.
 */
#[CoversClass(StockItem::class)]
final class StockItemTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Test Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * Create a valid StockItem with optional overrides.
     *
     * @param array<string, mixed> $overrides
     */
    private function createStockItem(array $overrides = []): StockItem
    {
        $defaults = [
            'stockItemId' => 'abc123-def456-ghi789',
            'sku' => 'TEST-SKU-001',
            'title' => 'Test Product Title',
            'barcode' => '1234567890123',
            'quantity' => 100,
            'available' => 95,
            'inOrder' => 5,
            'due' => 50,
            'minimumLevel' => 10,
            'purchasePrice' => 25.50,
            'retailPrice' => 49.99,
            'taxRate' => 20.0,
            'weight' => new Weight(1.5, WeightUnit::Kilogram),
            'dimensions' => new Dimensions(10.0, 5.0, 3.0),
            'isComposite' => false,
            'extendedProperties' => [],
        ];

        $data = \array_merge($defaults, $overrides);

        return new StockItem(...$data);
    }

    /*
    |--------------------------------------------------------------------------
    | Construction Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_a_stock_item_with_all_properties(): void
    {
        $stockItem = $this->createStockItem();

        $this->assertSame('abc123-def456-ghi789', $stockItem->stockItemId);
        $this->assertSame('TEST-SKU-001', $stockItem->sku);
        $this->assertSame('Test Product Title', $stockItem->title);
        $this->assertSame('1234567890123', $stockItem->barcode);
        $this->assertSame(100, $stockItem->quantity);
        $this->assertSame(95, $stockItem->available);
        $this->assertSame(5, $stockItem->inOrder);
        $this->assertSame(50, $stockItem->due);
        $this->assertSame(10, $stockItem->minimumLevel);
        $this->assertSame(25.50, $stockItem->purchasePrice);
        $this->assertSame(49.99, $stockItem->retailPrice);
        $this->assertSame(20.0, $stockItem->taxRate);
        $this->assertSame(1.5, $stockItem->weight->value);
        $this->assertSame(WeightUnit::Kilogram, $stockItem->weight->unit);
        $this->assertSame(10.0, $stockItem->dimensions->height);
        $this->assertSame(5.0, $stockItem->dimensions->width);
        $this->assertSame(3.0, $stockItem->dimensions->depth);
        $this->assertFalse($stockItem->isComposite);
        $this->assertSame([], $stockItem->extendedProperties);
    }

    /*
    |--------------------------------------------------------------------------
    | Extended Properties Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_accepts_extended_properties(): void
    {
        $ep1 = new StockItemExtendedProperty('row-1', 'Color', 'Blue', 'Attribute');
        $ep2 = new StockItemExtendedProperty('row-2', 'Size', 'Large', 'Attribute');

        $stockItem = $this->createStockItem([
            'extendedProperties' => [$ep1, $ep2],
        ]);

        $this->assertTrue($stockItem->hasExtendedProperties());
        $this->assertCount(2, $stockItem->extendedProperties);
    }

    #[Test]
    public function it_can_get_extended_property_by_name(): void
    {
        $ep1 = new StockItemExtendedProperty('row-1', 'Color', 'Blue', 'Attribute');
        $ep2 = new StockItemExtendedProperty('row-2', 'Size', 'Large', 'Attribute');

        $stockItem = $this->createStockItem([
            'extendedProperties' => [$ep1, $ep2],
        ]);

        $found = $stockItem->getExtendedProperty('Size');
        $this->assertNotNull($found);
        $this->assertSame('Large', $found->value);
    }

    #[Test]
    public function it_returns_null_for_missing_extended_property(): void
    {
        $stockItem = $this->createStockItem();

        $this->assertNull($stockItem->getExtendedProperty('NonExistent'));
    }

    #[Test]
    public function it_can_get_extended_property_value_directly(): void
    {
        $ep = new StockItemExtendedProperty('row-1', 'Material', 'Cotton', 'Attribute');

        $stockItem = $this->createStockItem([
            'extendedProperties' => [$ep],
        ]);

        $this->assertSame('Cotton', $stockItem->getExtendedPropertyValue('Material'));
        $this->assertNull($stockItem->getExtendedPropertyValue('NonExistent'));
    }

    /*
    |--------------------------------------------------------------------------
    | Edge Case Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_accepts_zero_quantity(): void
    {
        $stockItem = $this->createStockItem([
            'quantity' => 0,
            'available' => 0,
            'inOrder' => 0,
            'due' => 0,
        ]);

        $this->assertSame(0, $stockItem->quantity);
        $this->assertSame(0, $stockItem->available);
        $this->assertSame(0, $stockItem->inOrder);
        $this->assertSame(0, $stockItem->due);
    }

    #[Test]
    public function it_accepts_zero_prices(): void
    {
        $stockItem = $this->createStockItem([
            'purchasePrice' => 0.0,
            'retailPrice' => 0.0,
            'taxRate' => 0.0,
        ]);

        $this->assertSame(0.0, $stockItem->purchasePrice);
        $this->assertSame(0.0, $stockItem->retailPrice);
        $this->assertSame(0.0, $stockItem->taxRate);
    }

    #[Test]
    public function it_accepts_zero_dimensions(): void
    {
        $stockItem = $this->createStockItem([
            'dimensions' => Dimensions::zero(),
        ]);

        $this->assertTrue($stockItem->dimensions->isEmpty());
    }

    #[Test]
    public function it_accepts_zero_weight(): void
    {
        $stockItem = $this->createStockItem([
            'weight' => Weight::zero(),
        ]);

        $this->assertTrue($stockItem->weight->isEmpty());
    }

    #[Test]
    public function it_accepts_composite_flag_as_true(): void
    {
        $stockItem = $this->createStockItem(['isComposite' => true]);

        $this->assertTrue($stockItem->isComposite);
    }

    #[Test]
    public function it_accepts_empty_barcode(): void
    {
        $stockItem = $this->createStockItem(['barcode' => '']);

        $this->assertSame('', $stockItem->barcode);
    }

    #[Test]
    public function it_accepts_large_quantities(): void
    {
        $stockItem = $this->createStockItem([
            'quantity' => 999999,
            'available' => 999999,
        ]);

        $this->assertSame(999999, $stockItem->quantity);
        $this->assertSame(999999, $stockItem->available);
    }

    #[Test]
    public function it_accepts_decimal_precision_for_prices(): void
    {
        $stockItem = $this->createStockItem([
            'purchasePrice' => 123.456789,
            'retailPrice' => 999.999999,
        ]);

        $this->assertSame(123.456789, $stockItem->purchasePrice);
        $this->assertSame(999.999999, $stockItem->retailPrice);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_when_stock_item_id_is_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Stock item ID cannot be empty');

        $this->createStockItem(['stockItemId' => '']);
    }
}
