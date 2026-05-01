<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation\Http\Api\Resources;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\Enums\StockItemUpdateMode;
use App\Domain\Catalog\CustomFields\ValueObjects\ConfiguredFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldGeneralSettings;
use App\Domain\Catalog\CustomFields\ValueObjects\DateTimeCustomFieldValue;
use App\Domain\Catalog\CustomFields\ValueObjects\NullCustomFieldValue;
use App\Domain\Catalog\CustomFields\ValueObjects\ProductFieldSettings;
use App\Domain\Catalog\CustomFields\ValueObjects\ProductListCustomFieldValue;
use App\Domain\Catalog\CustomFields\ValueObjects\StringCustomFieldValue;
use App\Domain\Catalog\CustomFields\ValueObjects\ToggleCustomFieldValue;
use App\Domain\Catalog\CustomFields\ValueObjects\ValueListCustomFieldValue;
use App\Domain\ValueObjects\Uuid;
use App\Presentation\Http\Api\Resources\CustomFieldValueResource;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CustomFieldValueResource::class)]
final class CustomFieldValueResourceTest extends TestCase
{
    // ========================================================================
    // String subtype
    // ========================================================================

    #[Test]
    public function it_serializes_string_custom_field_value(): void
    {
        $definition = self::wrap(new CustomFieldDefinition(
            id: 1,
            name: 'material',
            type: CustomFieldType::Text,
            label: 'Material',
            itemType: CustomFieldItemType::Product,
            sortOrder: 3,
            allowedValues: null,
        ));
        $vo = new StringCustomFieldValue($definition, 'Cotton');

        $result = (new CustomFieldValueResource($vo))->toArray(Request::create('/'));

        self::assertSame('material', $result['name']);
        self::assertSame('text', $result['type']);
        self::assertSame('Material', $result['label']);
        self::assertSame('Cotton', $result['value']);
        self::assertNull($result['allowed_values']);
        self::assertSame(3, $result['sort_order']);
    }

    // ========================================================================
    // Toggle subtype
    // ========================================================================

    #[Test]
    public function it_serializes_toggle_custom_field_value(): void
    {
        $definition = self::wrap(new CustomFieldDefinition(
            id: 2,
            name: 'is_featured',
            type: CustomFieldType::Toggle,
            label: 'Featured',
            itemType: CustomFieldItemType::Product,
            sortOrder: 1,
            allowedValues: null,
        ));
        $vo = new ToggleCustomFieldValue($definition, true);

        $result = (new CustomFieldValueResource($vo))->toArray(Request::create('/'));

        self::assertSame('is_featured', $result['name']);
        self::assertSame('toggle', $result['type']);
        self::assertSame('Featured', $result['label']);
        self::assertTrue($result['value']);
        self::assertNull($result['allowed_values']);
        self::assertSame(1, $result['sort_order']);
    }

    // ========================================================================
    // DateTime subtype — ATOM formatting lives here
    // ========================================================================

    #[Test]
    public function it_serializes_datetime_custom_field_value_as_atom_string(): void
    {
        $definition = self::wrap(new CustomFieldDefinition(
            id: 3,
            name: 'published_at',
            type: CustomFieldType::DateTime,
            label: 'Published At',
            itemType: CustomFieldItemType::Product,
            sortOrder: 2,
            allowedValues: null,
        ));
        $dateTime = new DateTimeImmutable('2024-06-15T14:30:00+00:00');
        $vo = new DateTimeCustomFieldValue($definition, $dateTime);

        $result = (new CustomFieldValueResource($vo))->toArray(Request::create('/'));

        self::assertSame('published_at', $result['name']);
        self::assertSame('date_time', $result['type']);
        self::assertSame($dateTime->format(DateTimeInterface::ATOM), $result['value']);
        self::assertIsString($result['value']);
        self::assertNull($result['allowed_values']);
        self::assertSame(2, $result['sort_order']);
    }

    // ========================================================================
    // ValueList subtype
    // ========================================================================

    #[Test]
    public function it_serializes_value_list_custom_field_value(): void
    {
        $definition = self::wrap(new CustomFieldDefinition(
            id: 4,
            name: 'tags',
            type: CustomFieldType::ValueList,
            label: 'Tags',
            itemType: CustomFieldItemType::Product,
            sortOrder: 0,
            allowedValues: null,
        ));
        $vo = new ValueListCustomFieldValue($definition, ['tag1', 'tag2', 'tag3']);

        $result = (new CustomFieldValueResource($vo))->toArray(Request::create('/'));

        self::assertSame('tags', $result['name']);
        self::assertSame('value_list', $result['type']);
        self::assertSame('Tags', $result['label']);
        self::assertSame(['tag1', 'tag2', 'tag3'], $result['value']);
        self::assertNull($result['allowed_values']);
        self::assertSame(0, $result['sort_order']);
    }

    // ========================================================================
    // ProductList subtype
    // ========================================================================

    #[Test]
    public function it_serializes_product_list_custom_field_value(): void
    {
        $definition = self::wrap(new CustomFieldDefinition(
            id: 5,
            name: 'related_products',
            type: CustomFieldType::ProductList,
            label: 'Related Products',
            itemType: CustomFieldItemType::Product,
            sortOrder: 5,
            allowedValues: null,
        ));
        $vo = new ProductListCustomFieldValue($definition, [101, 202]);

        $result = (new CustomFieldValueResource($vo))->toArray(Request::create('/'));

        self::assertSame('related_products', $result['name']);
        self::assertSame('product_list', $result['type']);
        self::assertSame('Related Products', $result['label']);
        self::assertSame([101, 202], $result['value']);
        self::assertNull($result['allowed_values']);
        self::assertSame(5, $result['sort_order']);
    }

    // ========================================================================
    // Null subtype
    // ========================================================================

    #[Test]
    public function it_serializes_null_custom_field_value_with_null_value(): void
    {
        $definition = self::wrap(new CustomFieldDefinition(
            id: 6,
            name: 'color',
            type: CustomFieldType::Choice,
            label: 'Color',
            itemType: CustomFieldItemType::Product,
            sortOrder: 1,
            allowedValues: ['Red', 'Green', 'Blue'],
        ));
        $vo = new NullCustomFieldValue($definition);

        $result = (new CustomFieldValueResource($vo))->toArray(Request::create('/'));

        self::assertSame('color', $result['name']);
        self::assertSame('choice', $result['type']);
        self::assertSame('Color', $result['label']);
        self::assertNull($result['value']);
        self::assertSame(['Red', 'Green', 'Blue'], $result['allowed_values']);
        self::assertSame(1, $result['sort_order']);
    }

    // ========================================================================
    // allowed_values and sort_order pass-through
    // ========================================================================

    #[Test]
    public function it_includes_allowed_values_and_sort_order_from_definition(): void
    {
        $definition = self::wrap(new CustomFieldDefinition(
            id: 7,
            name: 'size',
            type: CustomFieldType::Choice,
            label: 'Size',
            itemType: CustomFieldItemType::Product,
            sortOrder: 9,
            allowedValues: ['S', 'M', 'L', 'XL'],
        ));
        $vo = new StringCustomFieldValue($definition, 'M');

        $result = (new CustomFieldValueResource($vo))->toArray(Request::create('/'));

        self::assertSame(['S', 'M', 'L', 'XL'], $result['allowed_values']);
        self::assertSame(9, $result['sort_order']);
    }

    // ========================================================================
    // general block
    // ========================================================================

    #[Test]
    public function it_includes_general_defaults_when_no_settings_row_exists(): void
    {
        $definition = self::wrap(new CustomFieldDefinition(
            id: 10,
            name: 'notes',
            type: CustomFieldType::Text,
            label: 'Notes',
            itemType: CustomFieldItemType::Product,
            sortOrder: 0,
            allowedValues: null,
        ));
        $vo = new StringCustomFieldValue($definition, 'some note');

        $result = (new CustomFieldValueResource($vo))->toArray(Request::create('/'));

        self::assertSame([
            'tooltip' => null,
            'select_type' => null,
            'suggest_common_data' => null,
            'admin_only' => false,
            'field_validation_rule' => null,
        ], $result['general']);
    }

    #[Test]
    public function it_includes_populated_general_block_when_settings_exist(): void
    {
        $base = new CustomFieldDefinition(
            id: 11,
            name: 'material',
            type: CustomFieldType::Text,
            label: 'Material',
            itemType: CustomFieldItemType::Product,
            sortOrder: 1,
            allowedValues: null,
        );
        $definition = new ConfiguredFieldDefinition(
            new Uuid('11111111-2222-3333-4444-555555555555'),
            $base,
            new CustomFieldGeneralSettings(
                tooltip: 'Enter the primary material',
                selectType: null,
                suggestCommonData: true,
                adminOnly: false,
                validationRule: null,
            ),
            null,
        );
        $vo = new StringCustomFieldValue($definition, 'cotton');

        $result = (new CustomFieldValueResource($vo))->toArray(Request::create('/'));

        self::assertSame([
            'tooltip' => 'Enter the primary material',
            'select_type' => null,
            'suggest_common_data' => true,
            'admin_only' => false,
            'field_validation_rule' => null,
        ], $result['general']);
    }

    // ========================================================================
    // product block
    // ========================================================================

    #[Test]
    public function it_includes_null_product_block_for_non_product_entity(): void
    {
        $definition = new ConfiguredFieldDefinition(
            new Uuid('11111111-2222-3333-4444-555555555555'),
            new CustomFieldDefinition(
                id: 12,
                name: 'brand_note',
                type: CustomFieldType::Text,
                label: 'Brand Note',
                itemType: CustomFieldItemType::Brand,
                sortOrder: 0,
                allowedValues: null,
            ),
            null,
            null,
        );
        $vo = new StringCustomFieldValue($definition, 'note');

        $result = (new CustomFieldValueResource($vo))->toArray(Request::create('/'));

        self::assertNull($result['product']);
    }

    #[Test]
    public function it_includes_product_defaults_when_product_field_has_no_settings_row(): void
    {
        $definition = self::wrap(new CustomFieldDefinition(
            id: 13,
            name: 'stock_note',
            type: CustomFieldType::Text,
            label: 'Stock Note',
            itemType: CustomFieldItemType::Product,
            sortOrder: 0,
            allowedValues: null,
        ));
        $vo = new StringCustomFieldValue($definition, 'in stock');

        $result = (new CustomFieldValueResource($vo))->toArray(Request::create('/'));

        self::assertSame(['stock_item_update_mode' => null], $result['product']);
    }

    #[Test]
    public function it_includes_populated_product_block_when_settings_exist(): void
    {
        $base = new CustomFieldDefinition(
            id: 14,
            name: 'update_mode',
            type: CustomFieldType::Text,
            label: 'Update Mode',
            itemType: CustomFieldItemType::Product,
            sortOrder: 0,
            allowedValues: null,
        );
        $definition = new ConfiguredFieldDefinition(
            new Uuid('11111111-2222-3333-4444-555555555555'),
            $base,
            null,
            new ProductFieldSettings(stockItemUpdateMode: StockItemUpdateMode::Single),
        );
        $vo = new StringCustomFieldValue($definition, 'value');

        $result = (new CustomFieldValueResource($vo))->toArray(Request::create('/'));

        self::assertSame(['stock_item_update_mode' => StockItemUpdateMode::Single->value], $result['product']);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private static function wrap(CustomFieldDefinition $base): ConfiguredFieldDefinition
    {
        return new ConfiguredFieldDefinition(
            new Uuid('11111111-2222-3333-4444-555555555555'),
            $base,
            null,
            null,
        );
    }
}
