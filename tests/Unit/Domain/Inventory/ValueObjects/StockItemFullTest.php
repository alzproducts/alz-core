<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Inventory\ValueObjects;

use App\Domain\Inventory\Enums\WeightUnit;
use App\Domain\Inventory\ValueObjects\Dimensions;
use App\Domain\Inventory\ValueObjects\StockItemExtendedProperty;
use App\Domain\Inventory\ValueObjects\StockItemFull;
use App\Domain\Inventory\ValueObjects\StockItemSupplier;
use App\Domain\Inventory\ValueObjects\Weight;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * StockItemFull Value Object Unit Tests.
 *
 * Tests the Domain value object for full stock item data including
 * category, extended properties, and supplier information.
 */
#[CoversClass(StockItemFull::class)]
final class StockItemFullTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Test Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * Create a valid StockItemFull with optional overrides.
     *
     * @param array<string, mixed> $overrides
     */
    private function createStockItemFull(array $overrides = []): StockItemFull
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
            'categoryId' => 'cat-123',
            'categoryName' => 'Electronics',
            'createdAt' => null,
            'extendedProperties' => [],
            'suppliers' => [],
        ];

        $data = \array_merge($defaults, $overrides);

        return new StockItemFull(...$data);
    }

    /**
     * Create a valid StockItemSupplier for testing.
     */
    private function createSupplier(bool $isDefault = false, string $name = 'Test Supplier'): StockItemSupplier
    {
        return new StockItemSupplier(
            supplierId: 'supplier-' . \uniqid(),
            supplierName: $name,
            code: null,
            supplierBarcode: null,
            purchasePrice: 10.00,
            isDefault: $isDefault,
            leadTime: null,
            supplierCurrency: null,
            minPrice: null,
            maxPrice: null,
            averagePrice: null,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Construction Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_a_stock_item_full_with_category_data(): void
    {
        $stockItem = $this->createStockItemFull();

        $this->assertSame('cat-123', $stockItem->categoryId);
        $this->assertSame('Electronics', $stockItem->categoryName);
    }

    #[Test]
    public function it_throws_when_stock_item_id_is_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Stock item ID cannot be empty');

        $this->createStockItemFull(['stockItemId' => '']);
    }

    /*
    |--------------------------------------------------------------------------
    | Supplier Helper Method Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function has_suppliers_returns_false_when_no_suppliers(): void
    {
        $stockItem = $this->createStockItemFull(['suppliers' => []]);

        $this->assertFalse($stockItem->hasSuppliers());
    }

    #[Test]
    public function has_suppliers_returns_true_when_suppliers_exist(): void
    {
        $supplier = $this->createSupplier();
        $stockItem = $this->createStockItemFull(['suppliers' => [$supplier]]);

        $this->assertTrue($stockItem->hasSuppliers());
    }

    #[Test]
    public function get_default_supplier_returns_null_when_no_suppliers(): void
    {
        $stockItem = $this->createStockItemFull(['suppliers' => []]);

        $this->assertNull($stockItem->getDefaultSupplier());
    }

    #[Test]
    public function get_default_supplier_returns_null_when_no_default_marked(): void
    {
        $supplier1 = $this->createSupplier(isDefault: false, name: 'Supplier A');
        $supplier2 = $this->createSupplier(isDefault: false, name: 'Supplier B');

        $stockItem = $this->createStockItemFull(['suppliers' => [$supplier1, $supplier2]]);

        $this->assertNull($stockItem->getDefaultSupplier());
    }

    #[Test]
    public function get_default_supplier_returns_the_default_supplier(): void
    {
        $nonDefault = $this->createSupplier(isDefault: false, name: 'Non-Default');
        $default = $this->createSupplier(isDefault: true, name: 'Default Supplier');

        $stockItem = $this->createStockItemFull(['suppliers' => [$nonDefault, $default]]);

        $result = $stockItem->getDefaultSupplier();

        $this->assertNotNull($result);
        $this->assertSame('Default Supplier', $result->supplierName);
        $this->assertTrue($result->isDefault);
    }

    #[Test]
    public function get_default_supplier_returns_first_default_when_multiple_defaults_exist(): void
    {
        $default1 = $this->createSupplier(isDefault: true, name: 'First Default');
        $default2 = $this->createSupplier(isDefault: true, name: 'Second Default');

        $stockItem = $this->createStockItemFull(['suppliers' => [$default1, $default2]]);

        $result = $stockItem->getDefaultSupplier();

        $this->assertNotNull($result);
        $this->assertSame('First Default', $result->supplierName);
    }

    /*
    |--------------------------------------------------------------------------
    | Extended Properties Helper Method Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function has_extended_properties_returns_false_when_empty(): void
    {
        $stockItem = $this->createStockItemFull(['extendedProperties' => []]);

        $this->assertFalse($stockItem->hasExtendedProperties());
    }

    #[Test]
    public function has_extended_properties_returns_true_when_properties_exist(): void
    {
        $property = new StockItemExtendedProperty('row-1', 'Color', 'Blue', 'Attribute');
        $stockItem = $this->createStockItemFull(['extendedProperties' => [$property]]);

        $this->assertTrue($stockItem->hasExtendedProperties());
    }

    #[Test]
    public function get_extended_property_returns_matching_property(): void
    {
        $property = new StockItemExtendedProperty('row-1', 'Color', 'Blue', 'Attribute');
        $stockItem = $this->createStockItemFull(['extendedProperties' => [$property]]);

        $result = $stockItem->getExtendedProperty('Color');

        $this->assertNotNull($result);
        $this->assertSame('Blue', $result->value);
    }

    #[Test]
    public function get_extended_property_returns_null_when_not_found(): void
    {
        $stockItem = $this->createStockItemFull(['extendedProperties' => []]);

        $this->assertNull($stockItem->getExtendedProperty('NonExistent'));
    }

    #[Test]
    public function get_extended_property_value_returns_value_directly(): void
    {
        $property = new StockItemExtendedProperty('row-1', 'Material', 'Cotton', 'Attribute');
        $stockItem = $this->createStockItemFull(['extendedProperties' => [$property]]);

        $this->assertSame('Cotton', $stockItem->getExtendedPropertyValue('Material'));
    }

    #[Test]
    public function get_extended_property_value_returns_null_when_not_found(): void
    {
        $stockItem = $this->createStockItemFull(['extendedProperties' => []]);

        $this->assertNull($stockItem->getExtendedPropertyValue('NonExistent'));
    }
}
