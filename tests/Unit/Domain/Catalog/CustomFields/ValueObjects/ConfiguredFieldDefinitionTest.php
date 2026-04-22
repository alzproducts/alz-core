<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\CustomFields\ValueObjects;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\Enums\LinnworksStockItemUpdateMode;
use App\Domain\Catalog\CustomFields\ValueObjects\ConfiguredFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldGeneralSettings;
use App\Domain\Catalog\CustomFields\ValueObjects\ProductFieldSettings;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfiguredFieldDefinition::class)]
final class ConfiguredFieldDefinitionTest extends TestCase
{
    #[Test]
    public function exposes_base_definition_and_settings(): void
    {
        $base = self::productDefinition();
        $general = new CustomFieldGeneralSettings(
            tooltip: 'Admin hover text',
            selectType: null,
            suggestCommonData: null,
            adminOnly: true,
            validationRule: null,
        );
        $product = new ProductFieldSettings(LinnworksStockItemUpdateMode::Single);

        $configured = new ConfiguredFieldDefinition($base, $general, $product);

        self::assertSame($base, $configured->base);
        self::assertSame($general, $configured->generalSettings);
        self::assertSame($product, $configured->productSettings);
    }

    #[Test]
    public function accepts_null_general_settings(): void
    {
        $configured = new ConfiguredFieldDefinition(
            self::productDefinition(),
            null,
            null,
        );

        self::assertNull($configured->generalSettings);
    }

    #[Test]
    public function accepts_null_product_settings_for_product_field(): void
    {
        $configured = new ConfiguredFieldDefinition(
            self::productDefinition(),
            null,
            null,
        );

        self::assertNull($configured->productSettings);
    }

    #[Test]
    #[DataProvider('nonProductItemTypes')]
    public function accepts_null_product_settings_for_non_product_field(CustomFieldItemType $itemType): void
    {
        $configured = new ConfiguredFieldDefinition(
            self::definitionWithItemType($itemType),
            null,
            null,
        );

        self::assertNull($configured->productSettings);
        self::assertSame($itemType, $configured->base->itemType);
    }

    #[Test]
    #[DataProvider('nonProductItemTypes')]
    public function rejects_product_settings_on_non_product_field(CustomFieldItemType $itemType): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ProductFieldSettings can only be attached to Product-type custom fields');

        new ConfiguredFieldDefinition(
            self::definitionWithItemType($itemType),
            null,
            new ProductFieldSettings(LinnworksStockItemUpdateMode::AllVariants),
        );
    }

    /**
     * @return iterable<string, array{CustomFieldItemType}>
     */
    public static function nonProductItemTypes(): iterable
    {
        yield 'category' => [CustomFieldItemType::Category];
        yield 'customer' => [CustomFieldItemType::Customer];
        yield 'brand' => [CustomFieldItemType::Brand];
        yield 'order' => [CustomFieldItemType::Order];
        yield 'page' => [CustomFieldItemType::Page];
        yield 'blog_post' => [CustomFieldItemType::BlogPost];
    }

    private static function productDefinition(): CustomFieldDefinition
    {
        return self::definitionWithItemType(CustomFieldItemType::Product);
    }

    private static function definitionWithItemType(CustomFieldItemType $itemType): CustomFieldDefinition
    {
        return new CustomFieldDefinition(
            id: 1,
            name: 'sample_field',
            type: CustomFieldType::Text,
            label: null,
            itemType: $itemType,
            sortOrder: null,
            allowedValues: null,
        );
    }
}
