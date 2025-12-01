<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Inventory\ValueObjects;

use App\Domain\Inventory\ValueObjects\StockItem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * StockItem Value Object Unit Tests.
 *
 * Tests the Domain value object for vendor-agnostic stock items.
 * This is a pure data container with no assertions - tests verify construction
 * and property access.
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
            'sku' => 'TEST-SKU-001',
            'title' => 'Test Product Title',
            'description' => 'A detailed product description',
            'barcode' => '1234567890123',
            'quantity' => 100,
            'available' => 95,
            'inOrder' => 5,
            'due' => 50,
            'minimumLevel' => 10,
            'purchasePrice' => 25.50,
            'retailPrice' => 49.99,
            'taxRate' => 20.0,
            'weight' => 1.5,
            'height' => 10.0,
            'width' => 5.0,
            'depth' => 3.0,
            'isComposite' => false,
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

        $this->assertSame('TEST-SKU-001', $stockItem->sku);
        $this->assertSame('Test Product Title', $stockItem->title);
        $this->assertSame('A detailed product description', $stockItem->description);
        $this->assertSame('1234567890123', $stockItem->barcode);
        $this->assertSame(100, $stockItem->quantity);
        $this->assertSame(95, $stockItem->available);
        $this->assertSame(5, $stockItem->inOrder);
        $this->assertSame(50, $stockItem->due);
        $this->assertSame(10, $stockItem->minimumLevel);
        $this->assertSame(25.50, $stockItem->purchasePrice);
        $this->assertSame(49.99, $stockItem->retailPrice);
        $this->assertSame(20.0, $stockItem->taxRate);
        $this->assertSame(1.5, $stockItem->weight);
        $this->assertSame(10.0, $stockItem->height);
        $this->assertSame(5.0, $stockItem->width);
        $this->assertSame(3.0, $stockItem->depth);
        $this->assertFalse($stockItem->isComposite);
    }

    /*
    |--------------------------------------------------------------------------
    | Nullable Field Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_accepts_null_description(): void
    {
        $stockItem = $this->createStockItem(['description' => null]);

        $this->assertNull($stockItem->description);
    }

    #[Test]
    public function it_accepts_null_weight(): void
    {
        $stockItem = $this->createStockItem(['weight' => null]);

        $this->assertNull($stockItem->weight);
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
            'height' => 0.0,
            'width' => 0.0,
            'depth' => 0.0,
        ]);

        $this->assertSame(0.0, $stockItem->height);
        $this->assertSame(0.0, $stockItem->width);
        $this->assertSame(0.0, $stockItem->depth);
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
}
