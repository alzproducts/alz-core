<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\Product\ValueObjects\ProductStock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductStock::class)]
final class ProductStockTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Pass-through primitives
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function quantity_is_passed_through(): void
    {
        $stock = new ProductStock(quantity: 42, available: null, inOrder: null, due: null, jit: false);

        self::assertSame(42, $stock->quantity);
    }

    #[Test]
    public function quantity_null_is_passed_through(): void
    {
        $stock = new ProductStock(quantity: null, available: null, inOrder: null, due: null, jit: false);

        self::assertNull($stock->quantity);
    }

    #[Test]
    public function zero_quantity_is_not_coalesced_to_null(): void
    {
        $stock = new ProductStock(quantity: 0, available: null, inOrder: null, due: null, jit: false);

        self::assertSame(0, $stock->quantity);
    }

    #[Test]
    public function all_fields_are_passed_through(): void
    {
        $stock = new ProductStock(quantity: 10, available: 8, inOrder: 2, due: 5, jit: true);

        self::assertSame(10, $stock->quantity);
        self::assertSame(8, $stock->available);
        self::assertSame(2, $stock->inOrder);
        self::assertSame(5, $stock->due);
        self::assertTrue($stock->jit);
    }

    /*
    |--------------------------------------------------------------------------
    | toArray serialisation
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function to_array_returns_expected_structure(): void
    {
        $stock = new ProductStock(quantity: 10, available: 8, inOrder: 2, due: 5, jit: true);

        $result = $stock->toArray();

        self::assertSame(10, $result['quantity']);
        self::assertSame(8, $result['available']);
        self::assertSame(2, $result['in_order']);
        self::assertSame(5, $result['due']);
        self::assertTrue($result['jit']);
    }

    #[Test]
    public function to_array_preserves_nulls(): void
    {
        $stock = new ProductStock(quantity: null, available: null, inOrder: null, due: null, jit: false);

        $result = $stock->toArray();

        self::assertNull($result['quantity']);
        self::assertNull($result['available']);
        self::assertNull($result['in_order']);
        self::assertNull($result['due']);
        self::assertFalse($result['jit']);
    }
}
