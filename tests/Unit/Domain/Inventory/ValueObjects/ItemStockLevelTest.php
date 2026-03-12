<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Inventory\ValueObjects;

use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Inventory\ValueObjects\ItemStockLevel;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ItemStockLevel Value Object Unit Tests.
 *
 * Tests the Domain value object for stock level update requests.
 * Validates assertion behavior for quantity constraints.
 * SKU validation is tested in SkuTest — ItemStockLevel delegates to the Sku type.
 */
#[CoversClass(ItemStockLevel::class)]
final class ItemStockLevelTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Construction Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_a_valid_item_stock_level(): void
    {
        $sku = Sku::fromTrusted('TEST-001');
        $stockLevel = new ItemStockLevel(sku: $sku, quantity: 100);

        $this->assertSame('TEST-001', $stockLevel->sku->value);
        $this->assertSame(100, $stockLevel->quantity);
    }

    #[Test]
    public function it_accepts_zero_quantity(): void
    {
        $stockLevel = new ItemStockLevel(sku: Sku::fromTrusted('OUT-OF-STOCK'), quantity: 0);

        $this->assertSame(0, $stockLevel->quantity);
    }

    #[Test]
    public function it_accepts_large_quantities(): void
    {
        $stockLevel = new ItemStockLevel(sku: Sku::fromTrusted('BULK-ITEM'), quantity: 999999);

        $this->assertSame(999999, $stockLevel->quantity);
    }

    /*
    |--------------------------------------------------------------------------
    | SKU Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    #[DataProvider('validSkuProvider')]
    public function it_accepts_various_valid_sku_formats(string $skuValue): void
    {
        $sku = Sku::fromTrusted($skuValue);
        $stockLevel = new ItemStockLevel(sku: $sku, quantity: 1);

        $this->assertSame($skuValue, $stockLevel->sku->value);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validSkuProvider(): array
    {
        return [
            'alphanumeric' => ['ABC123'],
            'with hyphens' => ['TEST-SKU-001'],
            'with underscores' => ['TEST_SKU_001'],
            'mixed format' => ['SKU-123_ABC'],
            'single character' => ['A'],
            'numbers only' => ['12345'],
            'lowercase' => ['sku-lowercase'],
            'with spaces' => ['SKU WITH SPACES'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Quantity Assertion Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_when_quantity_is_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Quantity cannot be negative');

        new ItemStockLevel(sku: Sku::fromTrusted('TEST-001'), quantity: -1);
    }

    #[Test]
    #[DataProvider('negativeQuantityProvider')]
    public function it_throws_for_various_negative_quantities(int $quantity): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Quantity cannot be negative');

        new ItemStockLevel(sku: Sku::fromTrusted('TEST-001'), quantity: $quantity);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function negativeQuantityProvider(): array
    {
        return [
            'minus one' => [-1],
            'large negative' => [-1000],
            'edge negative' => [-999999],
        ];
    }
}
