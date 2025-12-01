<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Linnworks\Responses;

use App\Infrastructure\Linnworks\Responses\SkuStockIdMapping;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SkuStockIdMapping Response DTO Unit Tests.
 *
 * Tests the Spatie Data DTO for SKU to StockItemId mappings:
 * - PascalCase input mapping for StockItemId
 * - Custom 'SKU' mapping (uppercase, not standard PascalCase)
 */
#[CoversClass(SkuStockIdMapping::class)]
final class SkuStockIdMappingTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | API Response Parsing Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_parses_stock_item_id_from_pascal_case(): void
    {
        $dto = SkuStockIdMapping::from([
            'StockItemId' => 'guid-12345-abcde',
            'SKU' => 'TEST-SKU',
        ]);

        $this->assertSame('guid-12345-abcde', $dto->stockItemId);
    }

    #[Test]
    public function it_parses_sku_from_uppercase_key(): void
    {
        // Linnworks returns 'SKU' in uppercase, not 'Sku'
        $dto = SkuStockIdMapping::from([
            'StockItemId' => 'guid-12345-abcde',
            'SKU' => 'ABC-123',
        ]);

        $this->assertSame('ABC-123', $dto->sku);
    }

    #[Test]
    public function it_creates_mapping_with_all_fields(): void
    {
        $dto = SkuStockIdMapping::from([
            'StockItemId' => 'stock-item-guid-xyz',
            'SKU' => 'PRODUCT-999',
        ]);

        $this->assertSame('stock-item-guid-xyz', $dto->stockItemId);
        $this->assertSame('PRODUCT-999', $dto->sku);
    }

    #[Test]
    public function it_handles_hyphenated_sku(): void
    {
        $dto = SkuStockIdMapping::from([
            'StockItemId' => 'guid-123',
            'SKU' => 'PART-A-001-B',
        ]);

        $this->assertSame('PART-A-001-B', $dto->sku);
    }

    #[Test]
    public function it_handles_numeric_sku(): void
    {
        $dto = SkuStockIdMapping::from([
            'StockItemId' => 'guid-456',
            'SKU' => '123456789',
        ]);

        $this->assertSame('123456789', $dto->sku);
    }

    #[Test]
    public function it_preserves_sku_case_sensitivity(): void
    {
        $dto = SkuStockIdMapping::from([
            'StockItemId' => 'guid-789',
            'SKU' => 'MixedCase-SKU-123',
        ]);

        $this->assertSame('MixedCase-SKU-123', $dto->sku);
    }
}
