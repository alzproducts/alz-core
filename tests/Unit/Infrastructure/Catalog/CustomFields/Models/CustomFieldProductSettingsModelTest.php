<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Catalog\CustomFields\Models;

use App\Domain\Catalog\CustomFields\Enums\StockItemUpdateMode;
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
        $model = self::modelWith(['stock_item_update_mode' => 'all_variants']);

        $settings = $model->toDomain();

        self::assertSame(StockItemUpdateMode::AllVariants, $settings->stockItemUpdateMode);
    }

    #[Test]
    public function to_domain_returns_null_when_column_unset(): void
    {
        $model = self::modelWith(['stock_item_update_mode' => null]);

        $settings = $model->toDomain();

        self::assertNull($settings->stockItemUpdateMode);
    }

    #[Test]
    public function to_domain_throws_value_error_for_unknown_update_mode(): void
    {
        $model = self::modelWith(['stock_item_update_mode' => 'bogus_mode']);

        $this->expectException(ValueError::class);
        $model->toDomain();
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
