<?php

declare(strict_types=1);

namespace Database\Factories\ProductSlice;

use App\Infrastructure\Shopwired\Models\CategoryModel;
use Illuminate\Support\Str;
use Override;

/**
 * @extends AbstractSliceRowFactory<CategoryModel>
 */
final class CategoryRowFactory extends AbstractSliceRowFactory
{
    protected $model = CategoryModel::class;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        return [
            'id' => $this->faker->uuid(),
            'external_id' => 0,
            'shopwired_created_at' => self::BASE_TIMESTAMP,
            'title' => Str::title($this->phrase($this->faker->numberBetween(1, 3))),
            'description' => $this->faker->sentence(),
            'description2' => null,
            'slug' => static fn(array $attributes): string => Str::slug(self::stringAttribute($attributes, 'title') . ' ' . self::stringAttribute($attributes, 'external_id')),
            'url' => static fn(array $attributes): string => '/' . self::stringAttribute($attributes, 'slug'),
            'active' => true,
            'featured' => false,
            'trade_only' => false,
            'sort_order' => $this->faker->numberBetween(0, 500),
            'meta_title' => null,
            'meta_description' => null,
            'meta_keywords' => null,
            'meta_no_index' => false,
            'image_url' => null,
            'parent_ids' => [],
            'custom_fields' => [],
            'created_at' => self::BASE_TIMESTAMP,
            'updated_at' => self::BASE_TIMESTAMP,
        ];
    }
}
