<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Inventory\ValueObjects;

use App\Domain\Inventory\ValueObjects\StockItemSupplierStat;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\IntId;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * StockItemSupplierStat Value Object Unit Tests.
 *
 * Tests the complete supplier stat record from the Linnworks bulk stats API.
 */
#[CoversClass(StockItemSupplierStat::class)]
final class StockItemSupplierStatTest extends TestCase
{
    private const string STOCK_ITEM_ID = 'b2c3d4e5-f6a7-4b8c-9d0e-1f2a3b4c5d6e';

    private const string SUPPLIER_ID = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';

    /*
    |--------------------------------------------------------------------------
    | Test Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * @param array<string, mixed> $overrides
     */
    private function createStat(array $overrides = []): StockItemSupplierStat
    {
        $defaults = [
            'stockItemId' => new Guid(self::STOCK_ITEM_ID),
            'stockItemIntId' => IntId::from(42),
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
            'averageLeadTime' => 4.5,
            'supplierMinOrderQty' => 10,
            'supplierPackSize' => 6,
        ];

        $data = \array_merge($defaults, $overrides);

        return new StockItemSupplierStat(...$data);
    }

    /*
    |--------------------------------------------------------------------------
    | Construction Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_a_stat_with_all_properties(): void
    {
        $stat = $this->createStat();

        $this->assertSame(self::STOCK_ITEM_ID, $stat->stockItemId->value);
        $this->assertSame(42, $stat->stockItemIntId?->value);
        $this->assertSame(self::SUPPLIER_ID, $stat->supplierId->value);
        $this->assertSame('Acme Supplies Ltd', $stat->supplierName);
        $this->assertSame('ACM-001', $stat->code);
        $this->assertSame('5060123456789', $stat->supplierBarcode);
        $this->assertSame(15.99, $stat->purchasePrice->toNet());
        $this->assertTrue($stat->isDefault);
        $this->assertSame(7, $stat->leadTime);
        $this->assertSame('GBP', $stat->supplierCurrency);
        $this->assertSame(12.00, $stat->minPrice?->toNet());
        $this->assertSame(20.00, $stat->maxPrice?->toNet());
        $this->assertSame(16.50, $stat->averagePrice?->toNet());
        $this->assertSame(4.5, $stat->averageLeadTime);
        $this->assertSame(10, $stat->supplierMinOrderQty);
        $this->assertSame(6, $stat->supplierPackSize);
    }

    #[Test]
    public function it_creates_a_stat_with_nullable_fields_as_null(): void
    {
        $stat = $this->createStat([
            'stockItemIntId' => null,
            'code' => null,
            'supplierBarcode' => null,
            'leadTime' => null,
            'supplierCurrency' => null,
            'minPrice' => null,
            'maxPrice' => null,
            'averagePrice' => null,
            'averageLeadTime' => null,
            'supplierMinOrderQty' => null,
            'supplierPackSize' => null,
        ]);

        $this->assertNull($stat->stockItemIntId);
        $this->assertNull($stat->code);
        $this->assertNull($stat->supplierBarcode);
        $this->assertNull($stat->leadTime);
        $this->assertNull($stat->supplierCurrency);
        $this->assertNull($stat->minPrice);
        $this->assertNull($stat->maxPrice);
        $this->assertNull($stat->averagePrice);
        $this->assertNull($stat->averageLeadTime);
        $this->assertNull($stat->supplierMinOrderQty);
        $this->assertNull($stat->supplierPackSize);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_when_supplier_name_is_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Supplier name cannot be empty');

        $this->createStat(['supplierName' => '']);
    }

    /*
    |--------------------------------------------------------------------------
    | withPurchasePrice Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_new_instance_with_updated_purchase_price(): void
    {
        $original = $this->createStat();
        $newPrice = Money::exclusive(25.00);

        $updated = $original->withPurchasePrice($newPrice);

        $this->assertNotSame($original, $updated);
        $this->assertSame(25.00, $updated->purchasePrice->toNet());
        $this->assertSame(15.99, $original->purchasePrice->toNet());
    }

    #[Test]
    public function it_preserves_all_fields_when_updating_purchase_price(): void
    {
        $original = $this->createStat();
        $updated = $original->withPurchasePrice(Money::exclusive(99.00));

        $this->assertSame($original->stockItemId->value, $updated->stockItemId->value);
        $this->assertSame($original->stockItemIntId?->value, $updated->stockItemIntId?->value);
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
        $this->assertSame($original->averageLeadTime, $updated->averageLeadTime);
        $this->assertSame($original->supplierMinOrderQty, $updated->supplierMinOrderQty);
        $this->assertSame($original->supplierPackSize, $updated->supplierPackSize);
    }

    /*
    |--------------------------------------------------------------------------
    | Edge Case Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_accepts_zero_purchase_price(): void
    {
        $stat = $this->createStat(['purchasePrice' => Money::exclusive(0.0)]);

        $this->assertSame(0.0, $stat->purchasePrice->toNet());
    }

    #[Test]
    public function it_accepts_fractional_average_lead_time(): void
    {
        $stat = $this->createStat(['averageLeadTime' => 3.75]);

        $this->assertSame(3.75, $stat->averageLeadTime);
    }
}
