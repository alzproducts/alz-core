<?php

declare(strict_types=1);

namespace Database\Factories\ProductSlice;

use App\Infrastructure\Catalog\Product\Models\ProductModel;
use Illuminate\Support\Str;
use Override;

/**
 * @extends AbstractSliceRowFactory<ProductModel>
 */
final class ProductRowFactory extends AbstractSliceRowFactory
{
    protected $model = ProductModel::class;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        return [
            'id' => $this->faker->uuid(),
            'external_id' => 0,
            'sku' => null,
            'title' => Str::title($this->phrase($this->faker->numberBetween(2, 5))),
            'description' => $this->faker->paragraph(),
            'slug' => static fn(array $attributes): string => Str::slug(self::stringAttribute($attributes, 'title') . ' ' . self::stringAttribute($attributes, 'external_id')),
            'url' => static fn(array $attributes): string => '/' . self::stringAttribute($attributes, 'slug'),
            'price' => 0.0,
            'cost_price' => null,
            'sale_price' => 0.0,
            'compare_price' => null,
            'stock' => $this->faker->numberBetween(0, 200),
            'is_active' => true,
            'vat_exclusive' => false,
            'vat_relief' => false,
            'weight' => $this->faker->numberBetween(1, 5000) / 1000,
            'meta_title' => null,
            'meta_description' => null,
            'gtin' => null,
            'category_ids' => [],
            'images' => [],
            'custom_fields' => [],
            'shopwired_created_at' => self::BASE_TIMESTAMP,
            'shopwired_updated_at' => self::BASE_TIMESTAMP,
            'created_at' => self::BASE_TIMESTAMP,
            'updated_at' => self::BASE_TIMESTAMP,
            'filters' => [],
            'sort_order' => $this->faker->numberBetween(0, 10_000),
        ];
    }
}
