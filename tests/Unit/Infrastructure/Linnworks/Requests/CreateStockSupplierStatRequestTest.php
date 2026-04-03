<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Linnworks\Requests;

use App\Domain\Inventory\ValueObjects\SupplierLinkParams;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Linnworks\Requests\CreateStockSupplierStatRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(CreateStockSupplierStatRequest::class)]
final class CreateStockSupplierStatRequestTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | fromResolved — full field mapping
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_maps_guid_and_supplier_link_params_to_array_output(): void
    {
        $stockItemId = new Guid('550e8400-e29b-41d4-a716-446655440000');
        $supplierId = new Guid('6ba7b810-9dad-11d1-80b4-00c04fd430c8');
        $params = new SupplierLinkParams(
            supplierId: $supplierId,
            purchasePrice: Money::exclusive(5.0),
            supplierCode: 'SUP-001',
            isDefault: true,
        );

        $result = CreateStockSupplierStatRequest::fromResolved($stockItemId, $params)->toArray();

        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $result['StockItemId']);
        $this->assertSame('6ba7b810-9dad-11d1-80b4-00c04fd430c8', $result['SupplierID']);
        $this->assertSame(5.0, $result['PurchasePrice']);
        $this->assertSame('SUP-001', $result['Code']);
        $this->assertTrue($result['IsDefault']);
    }

    /*
    |--------------------------------------------------------------------------
    | fromResolved — null field defaults
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_defaults_null_purchase_price_to_zero(): void
    {
        $stockItemId = new Guid('550e8400-e29b-41d4-a716-446655440000');
        $params = new SupplierLinkParams(
            supplierId: new Guid('6ba7b810-9dad-11d1-80b4-00c04fd430c8'),
            purchasePrice: null,
            supplierCode: 'SUP-001',
            isDefault: false,
        );

        $result = CreateStockSupplierStatRequest::fromResolved($stockItemId, $params)->toArray();

        $this->assertSame(0.0, $result['PurchasePrice']);
    }

    #[Test]
    public function it_defaults_null_supplier_code_to_empty_string(): void
    {
        $stockItemId = new Guid('550e8400-e29b-41d4-a716-446655440000');
        $params = new SupplierLinkParams(
            supplierId: new Guid('6ba7b810-9dad-11d1-80b4-00c04fd430c8'),
            purchasePrice: Money::exclusive(5.0),
            supplierCode: null,
            isDefault: false,
        );

        $result = CreateStockSupplierStatRequest::fromResolved($stockItemId, $params)->toArray();

        $this->assertSame('', $result['Code']);
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
        $params = new SupplierLinkParams(
            supplierId: new Guid('6ba7b810-9dad-11d1-80b4-00c04fd430c8'),
            purchasePrice: Money::exclusive(5.0),
            supplierCode: 'SUP-001',
            isDefault: true,
        );

        $result = CreateStockSupplierStatRequest::fromResolved($stockItemId, $params)->toArray();

        $this->assertArrayHasKey('StockItemId', $result);
        $this->assertArrayHasKey('SupplierID', $result);
        $this->assertArrayHasKey('PurchasePrice', $result);
        $this->assertArrayHasKey('Code', $result);
        $this->assertArrayHasKey('IsDefault', $result);
    }
}
