<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\Commands;

use App\Domain\Catalog\Product\Commands\UpdateBasicProductCommand;
use App\Domain\Catalog\Product\Enums\ProductType;
use App\Domain\Catalog\Product\ValueObjects\Gtin;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Inventory\ValueObjects\Weight;
use App\Domain\ValueObjects\IntId;
use App\Domain\ValueObjects\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UpdateBasicProductCommand::class)]
final class UpdateBasicProductCommandTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Construction Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_constructs_with_sku_identifier(): void
    {
        $identifier = Sku::fromTrusted('CURRENT-SKU');
        $command = new UpdateBasicProductCommand(identifier: $identifier);

        self::assertSame($identifier, $command->identifier);
        self::assertNull($command->type);
        self::assertNull($command->newSku);
        self::assertNull($command->costPrice);
        self::assertNull($command->weight);
        self::assertNull($command->gtin);
    }

    #[Test]
    public function it_constructs_with_product_type_main(): void
    {
        $command = new UpdateBasicProductCommand(
            identifier: Sku::fromTrusted('MAIN-SKU'),
            type: ProductType::Main,
        );

        self::assertSame(ProductType::Main, $command->type);
    }

    #[Test]
    public function it_constructs_with_product_type_variation(): void
    {
        $command = new UpdateBasicProductCommand(
            identifier: IntId::from(12345),
            type: ProductType::Variation,
            newSku: Sku::fromTrusted('NEW-SKU'),
        );

        self::assertSame(ProductType::Variation, $command->type);
        self::assertSame('NEW-SKU', $command->newSku?->value);
    }

    #[Test]
    public function it_constructs_with_int_id_identifier(): void
    {
        $identifier = IntId::from(12345);
        $command = new UpdateBasicProductCommand(identifier: $identifier);

        self::assertSame($identifier, $command->identifier);
        self::assertNull($command->newSku);
    }

    #[Test]
    public function it_constructs_with_all_fields_using_sku(): void
    {
        $identifier = Sku::fromTrusted('CURRENT-SKU');
        $newSku = Sku::fromTrusted('NEW-SKU');
        $costPrice = Money::exclusive(15.00);
        $weight = Weight::kilogram(1.5);
        $gtin = Gtin::fromTrusted('5060012345678');

        $command = new UpdateBasicProductCommand(
            identifier: $identifier,
            newSku: $newSku,
            costPrice: $costPrice,
            weight: $weight,
            gtin: $gtin,
        );

        self::assertSame($identifier, $command->identifier);
        self::assertSame($newSku, $command->newSku);
        self::assertSame($costPrice, $command->costPrice);
        self::assertSame($weight, $command->weight);
        self::assertSame($gtin, $command->gtin);
    }

    #[Test]
    public function it_constructs_with_int_id_and_new_sku(): void
    {
        $identifier = IntId::from(99999);
        $newSku = Sku::fromTrusted('NEW-SKU');

        $command = new UpdateBasicProductCommand(
            identifier: $identifier,
            newSku: $newSku,
        );

        self::assertSame($identifier, $command->identifier);
        self::assertSame($newSku, $command->newSku);
    }

    /*
    |--------------------------------------------------------------------------
    | hasAnyUpdate() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function has_any_update_returns_false_when_all_fields_null(): void
    {
        $command = new UpdateBasicProductCommand(identifier: Sku::fromTrusted('TEST-SKU'));

        self::assertFalse($command->hasAnyUpdate());
    }

    #[Test]
    public function has_any_update_returns_true_when_only_new_sku_is_set(): void
    {
        $command = new UpdateBasicProductCommand(
            identifier: Sku::fromTrusted('TEST-SKU'),
            newSku: Sku::fromTrusted('UPDATED-SKU'),
        );

        self::assertTrue($command->hasAnyUpdate());
    }

    #[Test]
    public function has_any_update_returns_true_when_only_cost_price_is_set(): void
    {
        $command = new UpdateBasicProductCommand(
            identifier: Sku::fromTrusted('TEST-SKU'),
            costPrice: Money::exclusive(10.00),
        );

        self::assertTrue($command->hasAnyUpdate());
    }

    #[Test]
    public function has_any_update_returns_true_when_only_weight_is_set(): void
    {
        $command = new UpdateBasicProductCommand(
            identifier: Sku::fromTrusted('TEST-SKU'),
            weight: Weight::gram(500),
        );

        self::assertTrue($command->hasAnyUpdate());
    }

    #[Test]
    public function has_any_update_returns_true_when_only_gtin_is_set(): void
    {
        $command = new UpdateBasicProductCommand(
            identifier: Sku::fromTrusted('TEST-SKU'),
            gtin: Gtin::fromTrusted('5060012345678'),
        );

        self::assertTrue($command->hasAnyUpdate());
    }

    #[Test]
    public function has_any_update_returns_true_when_multiple_fields_set(): void
    {
        $command = new UpdateBasicProductCommand(
            identifier: Sku::fromTrusted('TEST-SKU'),
            newSku: Sku::fromTrusted('MULTI-UPDATE'),
            gtin: Gtin::fromTrusted('5060012345678'),
        );

        self::assertTrue($command->hasAnyUpdate());
    }

    #[Test]
    public function has_any_update_returns_false_when_using_int_id_with_no_updates(): void
    {
        $command = new UpdateBasicProductCommand(identifier: IntId::from(12345));

        self::assertFalse($command->hasAnyUpdate());
    }

    #[Test]
    public function has_any_update_returns_true_when_using_int_id_with_new_sku(): void
    {
        $command = new UpdateBasicProductCommand(
            identifier: IntId::from(12345),
            newSku: Sku::fromTrusted('BRAND-NEW-SKU'),
        );

        self::assertTrue($command->hasAnyUpdate());
    }
}
