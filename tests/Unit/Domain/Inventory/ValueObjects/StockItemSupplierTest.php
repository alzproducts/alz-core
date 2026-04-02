<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Inventory\ValueObjects;

use App\Domain\Inventory\ValueObjects\StockItemSupplier;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\Guid;
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
    private const string SUPPLIER_ID = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';

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
            'supplierId' => new Guid(self::SUPPLIER_ID),
            'supplierName' => 'Acme Supplies Ltd',
            'code' => 'ACM-001',
            'supplierBarcode' => '5060123456789',
            'purchasePrice' => Money::exclusive(15.99),
            'isDefault' => true,
            'leadTime' => 7,
            'supplierCurrency' => 'GBP',
            'minPrice' => Money::exclusive(12.00),
            'maxPrice' => Money::exclusive(20.00),
            'averagePrice' => Money::exclusive(16.50),
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

        $this->assertSame(self::SUPPLIER_ID, $supplier->supplierId->value);
        $this->assertSame('Acme Supplies Ltd', $supplier->supplierName);
        $this->assertSame('ACM-001', $supplier->code);
        $this->assertSame('5060123456789', $supplier->supplierBarcode);
        $this->assertSame(15.99, $supplier->purchasePrice?->toNet());
        $this->assertTrue($supplier->isDefault);
        $this->assertSame(7, $supplier->leadTime);
        $this->assertSame('GBP', $supplier->supplierCurrency);
        $this->assertSame(12.00, $supplier->minPrice?->toNet());
        $this->assertSame(20.00, $supplier->maxPrice?->toNet());
        $this->assertSame(16.50, $supplier->averagePrice?->toNet());
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

    #[Test]
    public function it_defaults_new_bulk_stat_fields_to_null(): void
    {
        $supplier = $this->createSupplier();

        $this->assertNull($supplier->stockItemId);
        $this->assertNull($supplier->stockItemIntId);
        $this->assertNull($supplier->averageLeadTime);
        $this->assertNull($supplier->supplierMinOrderQty);
        $this->assertNull($supplier->supplierPackSize);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_when_supplier_id_is_invalid_uuid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid GUID format');

        new StockItemSupplier(
            supplierId: new Guid('not-a-uuid'),
            supplierName: 'Acme',
            code: null,
            supplierBarcode: null,
            purchasePrice: null,
            isDefault: false,
            leadTime: null,
            supplierCurrency: null,
            minPrice: null,
            maxPrice: null,
            averagePrice: null,
        );
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
    | withPurchasePrice Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_new_instance_with_updated_purchase_price(): void
    {
        $original = $this->createSupplier();
        $newPrice = Money::exclusive(25.00);

        $updated = $original->withPurchasePrice($newPrice);

        $this->assertNotSame($original, $updated);
        $this->assertSame(25.00, $updated->purchasePrice?->toNet());
        $this->assertSame(15.99, $original->purchasePrice?->toNet());
    }

    #[Test]
    public function it_preserves_all_fields_when_updating_purchase_price(): void
    {
        $original = $this->createSupplier();
        $updated = $original->withPurchasePrice(Money::exclusive(99.00));

        $this->assertSame($original->supplierId->value, $updated->supplierId->value);
        $this->assertSame($original->supplierName, $updated->supplierName);
        $this->assertSame($original->code, $updated->code);
        $this->assertSame($original->supplierBarcode, $updated->supplierBarcode);
        $this->assertSame($original->isDefault, $updated->isDefault);
        $this->assertSame($original->leadTime, $updated->leadTime);
        $this->assertSame($original->supplierCurrency, $updated->supplierCurrency);
        $this->assertSame($original->minPrice?->toNet(), $updated->minPrice?->toNet());
        $this->assertSame($original->maxPrice?->toNet(), $updated->maxPrice?->toNet());
        $this->assertSame($original->averagePrice?->toNet(), $updated->averagePrice?->toNet());
    }

    /*
    |--------------------------------------------------------------------------
    | Edge Case Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_accepts_zero_purchase_price(): void
    {
        $supplier = $this->createSupplier(['purchasePrice' => Money::exclusive(0.0)]);

        $this->assertSame(0.0, $supplier->purchasePrice?->toNet());
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
