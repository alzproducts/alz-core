<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\Product\ValueObjects\ProductVariationView;
use App\Domain\Catalog\Product\ValueObjects\Stock;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Stock::class)]
final class StockTest extends TestCase
{
    #[Test]
    public function stores_available_and_physical_stock(): void
    {
        $stock = new Stock(availableStock: 7, physicalStock: 10);

        self::assertSame(7, $stock->availableStock);
        self::assertSame(10, $stock->physicalStock);
    }

    #[Test]
    public function clamps_negative_available_stock_to_zero(): void
    {
        $stock = new Stock(availableStock: -4, physicalStock: 0);

        self::assertSame(0, $stock->availableStock);
        self::assertSame(0, $stock->physicalStock);
    }

    #[Test]
    public function clamps_negative_physical_stock_to_zero(): void
    {
        $stock = new Stock(availableStock: 0, physicalStock: -1);

        self::assertSame(0, $stock->physicalStock);

        // Larger magnitude kills the `max(-1, 0) == 0` boundary mutant
        $deeplyNegative = new Stock(availableStock: 0, physicalStock: -100);

        self::assertSame(0, $deeplyNegative->physicalStock);
    }

    #[Test]
    public function from_parent_and_variants_uses_parent_when_variations_null(): void
    {
        $stock = Stock::fromParentAndVariants(parentAvailable: 5, parentPhysical: 8, variations: null);

        self::assertSame(5, $stock->availableStock);
        self::assertSame(8, $stock->physicalStock);
    }

    #[Test]
    public function from_parent_and_variants_uses_parent_when_variations_empty(): void
    {
        $stock = Stock::fromParentAndVariants(parentAvailable: 5, parentPhysical: 8, variations: []);

        self::assertSame(5, $stock->availableStock);
        self::assertSame(8, $stock->physicalStock);
    }

    #[Test]
    public function from_parent_and_variants_sums_variations_when_present(): void
    {
        $v1 = $this->createVariation(availableStock: 2, physicalStock: 4);
        $v2 = $this->createVariation(availableStock: 3, physicalStock: 5);
        $v3 = $this->createVariation(availableStock: 0, physicalStock: 1);

        $stock = Stock::fromParentAndVariants(
            parentAvailable: 999,
            parentPhysical: 999,
            variations: [$v1, $v2, $v3],
        );

        self::assertSame(5, $stock->availableStock);
        self::assertSame(10, $stock->physicalStock);
    }

    private function createVariation(int $availableStock, int $physicalStock): ProductVariationView
    {
        static $id = 1;

        return new ProductVariationView(
            externalId: $id++,
            sku: null,
            gtin: null,
            price: 10.0,
            costPrice: null,
            salePrice: null,
            rrp: null,
            effectivePrice: 10.0,
            isOnSale: false,
            profitMargin: null,
            availableStock: $availableStock,
            physicalStock: $physicalStock,
            weight: null,
            vatExclusive: false,
            mpn: null,
            imageIndex: null,
            options: [],
            createdAt: new DateTimeImmutable('2024-01-01'),
            updatedAt: new DateTimeImmutable('2024-01-01'),
        );
    }
}
