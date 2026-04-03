<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\Product\ValueObjects\ProductInventory;
use App\Domain\Inventory\Enums\WeightUnit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductInventory::class)]
final class ProductInventoryTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Barcode parsing
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function valid_barcode_is_parsed_as_gtin(): void
    {
        $inventory = $this->createInventory(barcode: '5901234123457'); // valid EAN-13

        self::assertSame('5901234123457', $inventory->barcode?->value);
    }

    #[Test]
    public function invalid_barcode_returns_null(): void
    {
        $inventory = $this->createInventory(barcode: 'not-a-barcode');

        self::assertNull($inventory->barcode);
    }

    #[Test]
    public function empty_barcode_returns_null(): void
    {
        $inventory = $this->createInventory(barcode: '');

        self::assertNull($inventory->barcode);
    }

    #[Test]
    public function null_barcode_returns_null(): void
    {
        $inventory = $this->createInventory(barcode: null);

        self::assertNull($inventory->barcode);
    }

    /*
    |--------------------------------------------------------------------------
    | Weight construction
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function weight_is_constructed_when_value_and_unit_provided(): void
    {
        $inventory = $this->createInventory(weight: 1.5, weightUnit: 'Kilogram');

        self::assertSame(1.5, $inventory->weight?->value);
        self::assertSame(WeightUnit::Kilogram, $inventory->weight?->unit);
    }

    #[Test]
    public function weight_defaults_to_kilogram_when_unit_unknown(): void
    {
        $inventory = $this->createInventory(weight: 0.5, weightUnit: 'Unknown');

        self::assertSame(WeightUnit::Kilogram, $inventory->weight?->unit);
    }

    #[Test]
    public function weight_defaults_to_kilogram_when_unit_null(): void
    {
        $inventory = $this->createInventory(weight: 0.5, weightUnit: null);

        self::assertSame(WeightUnit::Kilogram, $inventory->weight?->unit);
    }

    #[Test]
    public function weight_null_when_value_null(): void
    {
        $inventory = $this->createInventory(weight: null, weightUnit: 'Kilogram');

        self::assertNull($inventory->weight);
    }

    /*
    |--------------------------------------------------------------------------
    | Dimensions construction
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function dimensions_constructed_when_all_values_provided(): void
    {
        $inventory = $this->createInventory(height: 10.0, width: 5.0, depth: 3.0);

        self::assertSame(10.0, $inventory->dimensions?->height);
        self::assertSame(5.0, $inventory->dimensions?->width);
        self::assertSame(3.0, $inventory->dimensions?->depth);
    }

    #[Test]
    public function dimensions_null_when_all_values_null(): void
    {
        $inventory = $this->createInventory(height: null, width: null, depth: null);

        self::assertNull($inventory->dimensions);
    }

    #[Test]
    public function dimensions_constructed_when_partial_values_provided(): void
    {
        $inventory = $this->createInventory(height: 10.0, width: null, depth: null);

        self::assertNotNull($inventory->dimensions);
        self::assertSame(10.0, $inventory->dimensions->height);
        self::assertSame(0.0, $inventory->dimensions->width);
        self::assertSame(0.0, $inventory->dimensions->depth);
    }

    /*
    |--------------------------------------------------------------------------
    | Pass-through primitives
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function minimum_level_is_passed_through(): void
    {
        $inventory = $this->createInventory(minimumLevel: 5);

        self::assertSame(5, $inventory->minimumLevel);
    }

    #[Test]
    public function minimum_level_null_is_passed_through(): void
    {
        $inventory = $this->createInventory(minimumLevel: null);

        self::assertNull($inventory->minimumLevel);
    }

    #[Test]
    public function is_composite_is_passed_through(): void
    {
        $inventory = $this->createInventory(isComposite: true);

        self::assertTrue($inventory->isComposite);
    }

    #[Test]
    public function category_name_is_passed_through(): void
    {
        $inventory = $this->createInventory(categoryName: 'Electronics');

        self::assertSame('Electronics', $inventory->categoryName);
    }

    /*
    |--------------------------------------------------------------------------
    | toArray serialisation
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function to_array_returns_expected_structure(): void
    {
        $inventory = $this->createInventory(
            barcode: '5901234123457',
            minimumLevel: 2,
            weight: 1.5,
            weightUnit: 'Kilogram',
            height: 10.0,
            width: 5.0,
            depth: 3.0,
            isComposite: false,
            categoryName: 'Electronics',
        );

        $result = $inventory->toArray();

        self::assertSame('5901234123457', $result['barcode']);
        self::assertSame(2, $result['minimum_level']);
        self::assertSame(1.5, $result['weight']['value']);
        self::assertSame('Kilogram', $result['weight']['unit']);
        self::assertSame(10.0, $result['dimensions']['height']);
        self::assertSame(5.0, $result['dimensions']['width']);
        self::assertSame(3.0, $result['dimensions']['depth']);
        self::assertFalse($result['is_composite']);
        self::assertSame('Electronics', $result['category_name']);
    }

    #[Test]
    public function to_array_returns_nulls_for_missing_fields(): void
    {
        $inventory = $this->createInventory(
            barcode: null,
            minimumLevel: null,
            weight: null,
            height: null,
            width: null,
            depth: null,
        );

        $result = $inventory->toArray();

        self::assertNull($result['barcode']);
        self::assertNull($result['minimum_level']);
        self::assertNull($result['weight']);
        self::assertNull($result['dimensions']);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function createInventory(
        ?string $barcode = null,
        ?int $minimumLevel = null,
        ?float $weight = null,
        ?string $weightUnit = null,
        ?float $height = null,
        ?float $width = null,
        ?float $depth = null,
        bool $isComposite = false,
        string $categoryName = 'Default',
    ): ProductInventory {
        return new ProductInventory(
            barcode: $barcode,
            minimumLevel: $minimumLevel,
            weight: $weight,
            weightUnit: $weightUnit,
            height: $height,
            width: $width,
            depth: $depth,
            isComposite: $isComposite,
            categoryName: $categoryName,
        );
    }
}
