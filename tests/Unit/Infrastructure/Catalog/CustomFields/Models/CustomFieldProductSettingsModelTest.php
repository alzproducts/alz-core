<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Catalog\CustomFields\Models;

use App\Domain\Catalog\CustomFields\Enums\LinnworksStockItemUpdateMode;
use App\Domain\Catalog\CustomFields\ValueObjects\ProductFieldSettings;
use App\Infrastructure\Catalog\CustomFields\Models\CustomFieldProductSettingsModel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ValueError;

#[CoversClass(CustomFieldProductSettingsModel::class)]
final class CustomFieldProductSettingsModelTest extends TestCase
{
    #[Test]
    public function to_domain_maps_update_mode(): void
    {
        $model = self::modelWith(['update_linnworks_stock_item' => 'all_variants']);

        $settings = $model->toDomain();

        self::assertSame(LinnworksStockItemUpdateMode::AllVariants, $settings->updateLinnworksStockItem);
    }

    #[Test]
    public function to_domain_returns_null_when_column_unset(): void
    {
        $model = self::modelWith(['update_linnworks_stock_item' => null]);

        $settings = $model->toDomain();

        self::assertNull($settings->updateLinnworksStockItem);
    }

    #[Test]
    public function to_domain_throws_value_error_for_unknown_update_mode(): void
    {
        $model = self::modelWith(['update_linnworks_stock_item' => 'bogus_mode']);

        $this->expectException(ValueError::class);
        $model->toDomain();
    }

    #[Test]
    public function from_domain_attributes_serialises_enum(): void
    {
        $settings = new ProductFieldSettings(LinnworksStockItemUpdateMode::Single);

        $attributes = CustomFieldProductSettingsModel::fromDomainAttributes($settings);

        self::assertSame([
            'update_linnworks_stock_item' => 'single',
        ], $attributes);
    }

    #[Test]
    public function from_domain_attributes_handles_null_mode(): void
    {
        $settings = new ProductFieldSettings(null);

        $attributes = CustomFieldProductSettingsModel::fromDomainAttributes($settings);

        self::assertSame([
            'update_linnworks_stock_item' => null,
        ], $attributes);
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private static function modelWith(array $attrs): CustomFieldProductSettingsModel
    {
        $model = new CustomFieldProductSettingsModel();
        $model->setRawAttributes([
            'id' => '33333333-3333-4333-8333-333333333333',
            'custom_field_definition_id' => '11111111-1111-4111-8111-111111111111',
            ...$attrs,
        ]);

        return $model;
    }
}
