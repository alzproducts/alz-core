<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Inventory\ValueObjects;

use App\Domain\Inventory\ValueObjects\StockItemSupplier;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * StockItemSupplier Value Object Unit Tests.
 *
 * Tests the Domain value object for supplier information attached to stock items.
 */
#[CoversClass(StockItemSupplier::class)]
final class StockItemSupplierTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Test Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * Create a valid StockItemSupplier with optional overrides.
     *
     * @param array<string, mixed> $overrides
     */
    private function createSupplier(array $overrides = []): StockItemSupplier
    {
        $defaults = [
            'supplierId' => 'supplier-uuid-123',
            'supplierName' => 'Acme Supplies Ltd',
            'code' => 'ACM-001',
            'supplierBarcode' => '5060123456789',
            'purchasePrice' => 15.99,
            'isDefault' => true,
            'leadTime' => 7,
            'supplierCurrency' => 'GBP',
            'minPrice' => 12.00,
            'maxPrice' => 20.00,
            'averagePrice' => 16.50,
        ];

        $data = \array_merge($defaults, $overrides);

        return new StockItemSupplier(...$data);
    }

    /*
    |--------------------------------------------------------------------------
    | Construction Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_a_supplier_with_all_properties(): void
    {
        $supplier = $this->createSupplier();

        $this->assertSame('supplier-uuid-123', $supplier->supplierId);
        $this->assertSame('Acme Supplies Ltd', $supplier->supplierName);
        $this->assertSame('ACM-001', $supplier->code);
        $this->assertSame('5060123456789', $supplier->supplierBarcode);
        $this->assertSame(15.99, $supplier->purchasePrice);
        $this->assertTrue($supplier->isDefault);
        $this->assertSame(7, $supplier->leadTime);
        $this->assertSame('GBP', $supplier->supplierCurrency);
        $this->assertSame(12.00, $supplier->minPrice);
        $this->assertSame(20.00, $supplier->maxPrice);
        $this->assertSame(16.50, $supplier->averagePrice);
    }

    #[Test]
    public function it_creates_a_supplier_with_nullable_fields_as_null(): void
    {
        $supplier = $this->createSupplier([
            'code' => null,
            'supplierBarcode' => null,
            'purchasePrice' => null,
            'leadTime' => null,
            'supplierCurrency' => null,
            'minPrice' => null,
            'maxPrice' => null,
            'averagePrice' => null,
        ]);

        $this->assertNull($supplier->code);
        $this->assertNull($supplier->supplierBarcode);
        $this->assertNull($supplier->purchasePrice);
        $this->assertNull($supplier->leadTime);
        $this->assertNull($supplier->supplierCurrency);
        $this->assertNull($supplier->minPrice);
        $this->assertNull($supplier->maxPrice);
        $this->assertNull($supplier->averagePrice);
    }

    #[Test]
    public function it_creates_a_non_default_supplier(): void
    {
        $supplier = $this->createSupplier(['isDefault' => false]);

        $this->assertFalse($supplier->isDefault);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_when_supplier_id_is_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Supplier ID cannot be empty');

        $this->createSupplier(['supplierId' => '']);
    }

    #[Test]
    public function it_throws_when_supplier_name_is_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Supplier name cannot be empty');

        $this->createSupplier(['supplierName' => '']);
    }

    /*
    |--------------------------------------------------------------------------
    | Edge Case Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_accepts_zero_purchase_price(): void
    {
        $supplier = $this->createSupplier(['purchasePrice' => 0.0]);

        $this->assertSame(0.0, $supplier->purchasePrice);
    }

    #[Test]
    public function it_accepts_zero_lead_time(): void
    {
        $supplier = $this->createSupplier(['leadTime' => 0]);

        $this->assertSame(0, $supplier->leadTime);
    }

    #[Test]
    public function it_accepts_long_supplier_name(): void
    {
        $longName = \str_repeat('A', 255);
        $supplier = $this->createSupplier(['supplierName' => $longName]);

        $this->assertSame($longName, $supplier->supplierName);
    }
}
