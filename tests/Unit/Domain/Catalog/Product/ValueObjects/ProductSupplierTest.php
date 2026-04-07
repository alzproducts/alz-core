<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\Product\ValueObjects\Gtin;
use App\Domain\Catalog\Product\ValueObjects\ProductSupplier;
use App\Domain\Shared\Money\ValueObjects\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductSupplier::class)]
final class ProductSupplierTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Pass-through primitives
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function all_fields_are_passed_through(): void
    {
        $purchasePrice = Money::exclusive(10.50);
        $minPrice = Money::exclusive(5.00);
        $maxPrice = Money::exclusive(20.00);
        $averagePrice = Money::exclusive(12.75);

        $supplier = new ProductSupplier(
            supplierName: 'Acme Ltd',
            purchasePrice: $purchasePrice,
            isDefault: true,
            code: 'SUP-001',
            supplierBarcode: Gtin::fromTrusted('1234567890123'),
            leadTime: 7,
            supplierMinOrderQty: 10,
            supplierPackSize: 5,
            minPrice: $minPrice,
            maxPrice: $maxPrice,
            averagePrice: $averagePrice,
            averageLeadTime: 6.5,
        );

        self::assertSame('Acme Ltd', $supplier->supplierName);
        self::assertSame($purchasePrice, $supplier->purchasePrice);
        self::assertTrue($supplier->isDefault);
        self::assertSame('SUP-001', $supplier->code);
        self::assertSame('1234567890123', $supplier->supplierBarcode?->value);
        self::assertSame(7, $supplier->leadTime);
        self::assertSame(10, $supplier->supplierMinOrderQty);
        self::assertSame(5, $supplier->supplierPackSize);
        self::assertSame($minPrice, $supplier->minPrice);
        self::assertSame($maxPrice, $supplier->maxPrice);
        self::assertSame($averagePrice, $supplier->averagePrice);
        self::assertSame(6.5, $supplier->averageLeadTime);
    }

    #[Test]
    public function defaults_are_applied_for_optional_fields(): void
    {
        $supplier = new ProductSupplier(
            supplierName: 'Basic Supplier',
            purchasePrice: Money::exclusive(8.00),
            isDefault: false,
        );

        self::assertNull($supplier->code);
        self::assertNull($supplier->supplierBarcode);
        self::assertNull($supplier->leadTime);
        self::assertNull($supplier->supplierMinOrderQty);
        self::assertNull($supplier->supplierPackSize);
        self::assertNull($supplier->minPrice);
        self::assertNull($supplier->maxPrice);
        self::assertNull($supplier->averagePrice);
        self::assertNull($supplier->averageLeadTime);
    }

    /*
    |--------------------------------------------------------------------------
    | toArray serialisation
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function to_array_returns_expected_structure_with_all_fields(): void
    {
        $supplier = new ProductSupplier(
            supplierName: 'Acme Ltd',
            purchasePrice: Money::exclusive(10.50),
            isDefault: true,
            code: 'SUP-001',
            supplierBarcode: Gtin::fromTrusted('1234567890123'),
            leadTime: 7,
            supplierMinOrderQty: 10,
            supplierPackSize: 5,
            minPrice: Money::exclusive(5.00),
            maxPrice: Money::exclusive(20.00),
            averagePrice: Money::exclusive(12.75),
            averageLeadTime: 6.5,
        );

        $result = $supplier->toArray();

        self::assertSame('Acme Ltd', $result['supplier_name']);
        self::assertSame(10.50, $result['purchase_price']);
        self::assertTrue($result['is_default']);
        self::assertSame('SUP-001', $result['code']);
        self::assertSame('1234567890123', $result['supplier_barcode']);
        self::assertSame(7, $result['lead_time']);
        self::assertSame(10, $result['supplier_min_order_qty']);
        self::assertSame(5, $result['supplier_pack_size']);
        self::assertSame(5.00, $result['min_price']);
        self::assertSame(20.00, $result['max_price']);
        self::assertSame(12.75, $result['average_price']);
        self::assertSame(6.5, $result['average_lead_time']);
    }

    #[Test]
    public function to_array_serializes_null_money_fields_as_null(): void
    {
        $supplier = new ProductSupplier(
            supplierName: 'Minimal Supplier',
            purchasePrice: null,
            isDefault: false,
            minPrice: null,
            maxPrice: null,
            averagePrice: null,
        );

        $result = $supplier->toArray();

        self::assertNull($result['purchase_price']);
        self::assertNull($result['min_price']);
        self::assertNull($result['max_price']);
        self::assertNull($result['average_price']);
    }

    #[Test]
    public function to_array_preserves_null_optional_fields(): void
    {
        $supplier = new ProductSupplier(
            supplierName: 'Minimal Supplier',
            purchasePrice: Money::exclusive(5.00),
            isDefault: false,
        );

        $result = $supplier->toArray();

        self::assertNull($result['code']);
        self::assertNull($result['supplier_barcode']);
        self::assertNull($result['lead_time']);
        self::assertNull($result['supplier_min_order_qty']);
        self::assertNull($result['supplier_pack_size']);
        self::assertNull($result['min_price']);
        self::assertNull($result['max_price']);
        self::assertNull($result['average_price']);
        self::assertNull($result['average_lead_time']);
    }

    #[Test]
    public function to_array_returns_correct_keys(): void
    {
        $supplier = new ProductSupplier(
            supplierName: 'Acme Ltd',
            purchasePrice: null,
            isDefault: false,
        );

        $result = $supplier->toArray();

        self::assertSame(12, \count($result));
        self::assertArrayHasKey('supplier_name', $result);
        self::assertArrayHasKey('purchase_price', $result);
        self::assertArrayHasKey('is_default', $result);
        self::assertArrayHasKey('code', $result);
        self::assertArrayHasKey('supplier_barcode', $result);
        self::assertArrayHasKey('lead_time', $result);
        self::assertArrayHasKey('supplier_min_order_qty', $result);
        self::assertArrayHasKey('supplier_pack_size', $result);
        self::assertArrayHasKey('min_price', $result);
        self::assertArrayHasKey('max_price', $result);
        self::assertArrayHasKey('average_price', $result);
        self::assertArrayHasKey('average_lead_time', $result);
    }
}
