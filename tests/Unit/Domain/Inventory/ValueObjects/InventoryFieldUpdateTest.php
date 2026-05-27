<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Inventory\ValueObjects;

use App\Domain\Catalog\Product\ValueObjects\Gtin;
use App\Domain\Inventory\Enums\InventoryUpdatableField;
use App\Domain\Inventory\ValueObjects\InventoryFieldUpdate;
use App\Domain\Inventory\ValueObjects\Weight;
use App\Domain\Shared\Money\ValueObjects\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InventoryFieldUpdate::class)]
final class InventoryFieldUpdateTest extends TestCase
{
    #[Test]
    public function category_factory_sets_field_and_value(): void
    {
        $update = InventoryFieldUpdate::category('Widgets');

        self::assertSame(InventoryUpdatableField::Category, $update->field);
        self::assertSame('Widgets', $update->value);
    }

    #[Test]
    public function minimum_level_factory_stringifies_integer(): void
    {
        $update = InventoryFieldUpdate::minimumLevel(20);

        self::assertSame(InventoryUpdatableField::MinimumLevel, $update->field);
        self::assertSame('20', $update->value);
    }

    #[Test]
    public function minimum_level_stringifies_zero(): void
    {
        $update = InventoryFieldUpdate::minimumLevel(0);

        self::assertSame('0', $update->value);
    }

    #[Test]
    public function minimum_level_stringifies_negative_value(): void
    {
        $update = InventoryFieldUpdate::minimumLevel(-5);

        self::assertSame('-5', $update->value);
    }

    #[Test]
    public function jit_factory_returns_literal_true_string_when_enabled(): void
    {
        $update = InventoryFieldUpdate::jit(true);

        self::assertSame(InventoryUpdatableField::JIT, $update->field);
        self::assertSame('true', $update->value);
    }

    #[Test]
    public function jit_factory_returns_literal_false_string_when_disabled(): void
    {
        $update = InventoryFieldUpdate::jit(false);

        self::assertSame('false', $update->value);
    }

    #[Test]
    public function retail_price_factory_serializes_gross_money(): void
    {
        $update = InventoryFieldUpdate::retailPrice(Money::inclusive(19.99));

        self::assertSame(InventoryUpdatableField::RetailPrice, $update->field);
        self::assertSame('19.99', $update->value);
    }

    #[Test]
    public function purchase_price_factory_serializes_net_money(): void
    {
        $update = InventoryFieldUpdate::purchasePrice(Money::exclusive(10.50));

        self::assertSame(InventoryUpdatableField::PurchasePrice, $update->field);
        self::assertSame('10.5', $update->value);
    }

    #[Test]
    public function bin_rack_factory_sets_field_and_value(): void
    {
        $update = InventoryFieldUpdate::binRack('A1-03');

        self::assertSame(InventoryUpdatableField::BinRack, $update->field);
        self::assertSame('A1-03', $update->value);
    }

    #[Test]
    public function barcode_factory_unwraps_gtin_value(): void
    {
        $update = InventoryFieldUpdate::barcode(Gtin::fromTrusted('9780201633610'));

        self::assertSame(InventoryUpdatableField::Barcode, $update->field);
        self::assertSame('9780201633610', $update->value);
    }

    #[Test]
    public function weight_factory_stringifies_kilogram_value(): void
    {
        $update = InventoryFieldUpdate::weight(Weight::kilogram(2.5));

        self::assertSame(InventoryUpdatableField::Weight, $update->field);
        self::assertSame('2.5', $update->value);
    }

    #[Test]
    public function weight_factory_converts_grams_to_kilograms(): void
    {
        $update = InventoryFieldUpdate::weight(Weight::gram(1500.0));

        self::assertSame('1.5', $update->value);
    }

    #[Test]
    public function title_factory_sets_field_and_value(): void
    {
        $update = InventoryFieldUpdate::title('Premium Widget');

        self::assertSame(InventoryUpdatableField::Title, $update->field);
        self::assertSame('Premium Widget', $update->value);
    }
}
