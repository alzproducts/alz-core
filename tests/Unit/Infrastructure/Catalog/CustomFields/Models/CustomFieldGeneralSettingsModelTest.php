<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Catalog\CustomFields\Models;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldValidationRule;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldValueSelectType;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldGeneralSettings;
use App\Infrastructure\Catalog\CustomFields\Models\CustomFieldGeneralSettingsModel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ValueError;

#[CoversClass(CustomFieldGeneralSettingsModel::class)]
final class CustomFieldGeneralSettingsModelTest extends TestCase
{
    #[Test]
    public function to_domain_maps_all_fields(): void
    {
        $model = self::modelWith([
            'tooltip' => 'Shown on hover',
            'select_type' => 'brand',
            'suggest_common_data' => true,
            'admin_only' => true,
            'field_validation_rule' => 1,
        ]);

        $settings = $model->toDomain();

        self::assertSame('Shown on hover', $settings->tooltip);
        self::assertSame(CustomFieldValueSelectType::Brand, $settings->selectType);
        self::assertTrue($settings->suggestCommonData);
        self::assertTrue($settings->adminOnly);
        self::assertSame(CustomFieldValidationRule::Url, $settings->validationRule);
    }

    #[Test]
    public function to_domain_returns_nulls_for_unset_optional_columns(): void
    {
        $model = self::modelWith([
            'tooltip' => null,
            'select_type' => null,
            'suggest_common_data' => null,
            'admin_only' => false,
            'field_validation_rule' => null,
        ]);

        $settings = $model->toDomain();

        self::assertNull($settings->tooltip);
        self::assertNull($settings->selectType);
        self::assertNull($settings->suggestCommonData);
        self::assertFalse($settings->adminOnly);
        self::assertNull($settings->validationRule);
    }

    #[Test]
    public function to_domain_throws_value_error_for_unknown_select_type(): void
    {
        $model = self::modelWith([
            'select_type' => 'unknown_type',
            'admin_only' => false,
        ]);

        $this->expectException(ValueError::class);
        $model->toDomain();
    }

    #[Test]
    public function to_domain_throws_value_error_for_unknown_validation_rule(): void
    {
        $model = self::modelWith([
            'admin_only' => false,
            'field_validation_rule' => 99,
        ]);

        $this->expectException(ValueError::class);
        $model->toDomain();
    }

    #[Test]
    public function from_domain_attributes_serialises_enum_values(): void
    {
        $settings = new CustomFieldGeneralSettings(
            tooltip: 'Hover text',
            selectType: CustomFieldValueSelectType::Product,
            suggestCommonData: false,
            adminOnly: true,
            validationRule: CustomFieldValidationRule::Integer,
        );

        $attributes = CustomFieldGeneralSettingsModel::fromDomainAttributes($settings);

        self::assertSame([
            'tooltip' => 'Hover text',
            'select_type' => 'product',
            'suggest_common_data' => false,
            'admin_only' => true,
            'field_validation_rule' => 3,
        ], $attributes);
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private static function modelWith(array $attrs): CustomFieldGeneralSettingsModel
    {
        $model = new CustomFieldGeneralSettingsModel();
        $model->setRawAttributes([
            'id' => '22222222-2222-4222-8222-222222222222',
            'custom_field_definition_id' => '11111111-1111-4111-8111-111111111111',
            ...$attrs,
        ]);

        return $model;
    }
}
