<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired\Models;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldValidationRule;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldValueSelectType;
use App\Domain\Catalog\CustomFields\Enums\StockItemUpdateMode;
use App\Infrastructure\Catalog\CustomFields\Models\CustomFieldGeneralSettingsModel;
use App\Infrastructure\Catalog\CustomFields\Models\CustomFieldProductSettingsModel;
use App\Infrastructure\Shopwired\Models\CustomFieldDefinitionModel;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for the read-path hydration branching in CustomFieldDefinitionModel.
 *
 * Uses Eloquent's in-memory setRelation() to simulate eager-load results without
 * touching the database. Covers all four combinations of itemType × settings rows.
 */
#[CoversClass(CustomFieldDefinitionModel::class)]
final class CustomFieldDefinitionModelTest extends TestCase
{
    #[Test]
    public function hydrates_wrapper_with_both_settings_when_eager_loaded(): void
    {
        $model = self::definitionModel(itemType: CustomFieldItemType::Product);
        $model->setRelation('generalSettings', self::generalSettingsModel(
            tooltip: 'Shown on hover',
            selectType: CustomFieldValueSelectType::Brand,
            suggestCommonData: true,
            adminOnly: true,
            validationRule: CustomFieldValidationRule::Url,
        ));
        $model->setRelation('productSettings', self::productSettingsModel(
            updateMode: StockItemUpdateMode::AllVariants,
        ));

        $configured = $model->toConfiguredDomain();

        self::assertSame(42, $configured->base->id);
        self::assertNotNull($configured->generalSettings);
        self::assertSame('Shown on hover', $configured->generalSettings->tooltip);
        self::assertSame(CustomFieldValueSelectType::Brand, $configured->generalSettings->selectType);
        self::assertTrue($configured->generalSettings->suggestCommonData);
        self::assertTrue($configured->generalSettings->adminOnly);
        self::assertSame(CustomFieldValidationRule::Url, $configured->generalSettings->validationRule);
        self::assertNotNull($configured->productSettings);
        self::assertSame(
            StockItemUpdateMode::AllVariants,
            $configured->productSettings->stockItemUpdateMode,
        );
    }

    #[Test]
    public function general_settings_is_null_when_relation_loaded_but_no_row_exists(): void
    {
        $model = self::definitionModel(itemType: CustomFieldItemType::Product);
        $model->setRelation('generalSettings', null);
        $model->setRelation('productSettings', null);

        $configured = $model->toConfiguredDomain();

        self::assertNull($configured->generalSettings);
        self::assertNull($configured->productSettings);
    }

    #[Test]
    public function fails_fast_when_general_settings_relation_not_eager_loaded(): void
    {
        $model = self::definitionModel(itemType: CustomFieldItemType::Product);
        // Deliberately omit setRelation() — relationLoaded() returns false

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('generalSettings relation must be eager-loaded');

        $model->toConfiguredDomain();
    }

    #[Test]
    public function fails_fast_when_product_settings_relation_not_eager_loaded(): void
    {
        $model = self::definitionModel(itemType: CustomFieldItemType::Product);
        // generalSettings loaded, productSettings deliberately omitted
        $model->setRelation('generalSettings', null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('productSettings relation must be eager-loaded');

        $model->toConfiguredDomain();
    }

    #[Test]
    public function skips_product_settings_for_non_product_field_without_requiring_relation(): void
    {
        $model = self::definitionModel(itemType: CustomFieldItemType::Category);
        $model->setRelation('generalSettings', null);
        // productSettings relation intentionally NOT loaded — must not be asserted for non-product fields

        $configured = $model->toConfiguredDomain();

        self::assertSame(CustomFieldItemType::Category, $configured->base->itemType);
        self::assertNull($configured->productSettings);
    }

    #[Test]
    public function null_product_settings_for_product_field_when_row_absent(): void
    {
        $model = self::definitionModel(itemType: CustomFieldItemType::Product);
        $model->setRelation('generalSettings', null);
        $model->setRelation('productSettings', null);

        $configured = $model->toConfiguredDomain();

        self::assertNull($configured->productSettings);
    }

    private static function definitionModel(CustomFieldItemType $itemType): CustomFieldDefinitionModel
    {
        $model = new CustomFieldDefinitionModel();
        $model->setRawAttributes([
            'id' => '11111111-1111-4111-8111-111111111111',
            'external_id' => 42,
            'name' => 'sample_field',
            'type' => CustomFieldType::Text->value,
            'label' => null,
            'item_type' => $itemType->value,
            'sort_order' => null,
            'allowed_values' => null,
        ]);

        return $model;
    }

    private static function generalSettingsModel(
        ?string $tooltip,
        ?CustomFieldValueSelectType $selectType,
        ?bool $suggestCommonData,
        bool $adminOnly,
        ?CustomFieldValidationRule $validationRule,
    ): CustomFieldGeneralSettingsModel {
        $model = new CustomFieldGeneralSettingsModel();
        $model->setRawAttributes([
            'id' => '22222222-2222-4222-8222-222222222222',
            'custom_field_definition_id' => '11111111-1111-4111-8111-111111111111',
            'tooltip' => $tooltip,
            'select_type' => $selectType?->value,
            'suggest_common_data' => $suggestCommonData,
            'admin_only' => $adminOnly,
            'field_validation_rule' => $validationRule?->value,
        ]);

        return $model;
    }

    private static function productSettingsModel(
        ?StockItemUpdateMode $updateMode,
    ): CustomFieldProductSettingsModel {
        $model = new CustomFieldProductSettingsModel();
        $model->setRawAttributes([
            'id' => '33333333-3333-4333-8333-333333333333',
            'custom_field_definition_id' => '11111111-1111-4111-8111-111111111111',
            'stock_item_update_mode' => $updateMode?->value,
        ]);

        return $model;
    }
}
