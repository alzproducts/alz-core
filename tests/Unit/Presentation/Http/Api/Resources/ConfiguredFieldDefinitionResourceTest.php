<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation\Http\Api\Resources;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldValidationRule;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldValueSelectType;
use App\Domain\Catalog\CustomFields\Enums\StockItemUpdateMode;
use App\Domain\Catalog\CustomFields\ValueObjects\ConfiguredFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldGeneralSettings;
use App\Domain\Catalog\CustomFields\ValueObjects\ProductFieldSettings;
use App\Presentation\Http\Api\Resources\ConfiguredFieldDefinitionResource;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(ConfiguredFieldDefinitionResource::class)]
final class ConfiguredFieldDefinitionResourceTest extends TestCase
{
    #[Test]
    public function general_block_defaults_admin_only_to_false_when_settings_row_missing(): void
    {
        $definition = new ConfiguredFieldDefinition(
            base: self::productBase(),
            generalSettings: null,
            productSettings: null,
        );

        $array = (new ConfiguredFieldDefinitionResource($definition))->toArray(new Request());

        self::assertSame(
            [
                'tooltip' => null,
                'select_type' => null,
                'suggest_common_data' => null,
                'admin_only' => false,
                'field_validation_rule' => null,
            ],
            $array['general'],
        );
    }

    #[Test]
    public function general_block_reflects_stored_settings_when_row_present(): void
    {
        $definition = new ConfiguredFieldDefinition(
            base: self::productBase(),
            generalSettings: new CustomFieldGeneralSettings(
                tooltip: 'Help text',
                selectType: CustomFieldValueSelectType::Category,
                suggestCommonData: true,
                adminOnly: true,
                validationRule: CustomFieldValidationRule::Integer,
            ),
            productSettings: null,
        );

        $array = (new ConfiguredFieldDefinitionResource($definition))->toArray(new Request());

        $this->assertSame(
            [
                'tooltip' => 'Help text',
                'select_type' => CustomFieldValueSelectType::Category->value,
                'suggest_common_data' => true,
                'admin_only' => true,
                'field_validation_rule' => CustomFieldValidationRule::Integer->value,
            ],
            $array['general'],
        );
    }

    #[Test]
    public function product_block_is_null_for_non_product_definitions(): void
    {
        $definition = new ConfiguredFieldDefinition(
            base: new CustomFieldDefinition(
                id: 7,
                name: 'for_customer',
                type: CustomFieldType::Text,
                label: null,
                itemType: CustomFieldItemType::Customer,
                sortOrder: 0,
                allowedValues: null,
            ),
            generalSettings: null,
            productSettings: null,
        );

        $array = (new ConfiguredFieldDefinitionResource($definition))->toArray(new Request());

        $this->assertNull($array['product']);
    }

    #[Test]
    public function product_block_is_null_when_product_field_has_no_settings_row(): void
    {
        $definition = new ConfiguredFieldDefinition(
            base: self::productBase(),
            generalSettings: null,
            productSettings: null,
        );

        $array = (new ConfiguredFieldDefinitionResource($definition))->toArray(new Request());

        $this->assertNull($array['product']);
    }

    #[Test]
    public function product_block_reflects_settings_when_present(): void
    {
        $definition = new ConfiguredFieldDefinition(
            base: self::productBase(),
            generalSettings: null,
            productSettings: new ProductFieldSettings(
                stockItemUpdateMode: StockItemUpdateMode::Single,
            ),
        );

        $array = (new ConfiguredFieldDefinitionResource($definition))->toArray(new Request());

        $this->assertSame(
            ['stock_item_update_mode' => StockItemUpdateMode::Single->value],
            $array['product'],
        );
    }

    private static function productBase(): CustomFieldDefinition
    {
        return new CustomFieldDefinition(
            id: 42,
            name: 'product_detail',
            type: CustomFieldType::Text,
            label: 'Product Detail',
            itemType: CustomFieldItemType::Product,
            sortOrder: 1,
            allowedValues: null,
        );
    }
}
