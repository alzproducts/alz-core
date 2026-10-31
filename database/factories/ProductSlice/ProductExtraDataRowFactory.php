<?php

declare(strict_types=1);

namespace Database\Factories\ProductSlice;

use App\Infrastructure\Catalog\Product\Models\ProductExtraDataModel;
use Override;

/**
 * @extends AbstractSliceRowFactory<ProductExtraDataModel>
 */
final class ProductExtraDataRowFactory extends AbstractSliceRowFactory
{
    protected $model = ProductExtraDataModel::class;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        return [
            'id' => $this->faker->uuid(),
            'sku' => '',
            'rrp' => $this->faker->numberBetween(199, 49_999) / 100,
            'created_at' => self::BASE_TIMESTAMP,
            'updated_at' => self::BASE_TIMESTAMP,
        ];
    }
}
