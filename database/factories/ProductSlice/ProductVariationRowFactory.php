<?php

declare(strict_types=1);

namespace Database\Factories\ProductSlice;

use App\Infrastructure\Catalog\Product\Models\ProductVariationModel;
use Override;

/**
 * @extends AbstractSliceRowFactory<ProductVariationModel>
 */
final class ProductVariationRowFactory extends AbstractSliceRowFactory
{
    protected $model = ProductVariationModel::class;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        return [
            'id' => $this->faker->uuid(),
            'product_id' => '',
            'product_external_id' => 0,
            'external_id' => 0,
            'sku' => null,
            'price' => 0.0,
            'cost_price' => null,
            'sale_price' => 0.0,
            'stock' => $this->faker->numberBetween(0, 100),
            'weight' => $this->faker->numberBetween(1, 5000) / 1000,
            'gtin' => null,
            'mpn' => null,
            'image_index' => null,
            'options' => [],
            'created_at' => self::BASE_TIMESTAMP,
            'updated_at' => self::BASE_TIMESTAMP,
        ];
    }
}
