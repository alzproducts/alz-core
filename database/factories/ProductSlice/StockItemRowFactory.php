<?php

declare(strict_types=1);

namespace Database\Factories\ProductSlice;

use App\Infrastructure\Linnworks\Models\StockItemModel;
use Illuminate\Support\Str;
use Override;

/**
 * @extends AbstractSliceRowFactory<StockItemModel>
 */
final class StockItemRowFactory extends AbstractSliceRowFactory
{
    protected $model = StockItemModel::class;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        $quantity = $this->faker->numberBetween(0, 300);

        return [
            'id' => $this->faker->uuid(),
            'stock_item_id' => $this->faker->uuid(),
            'item_number' => '',
            'item_title' => Str::title($this->phrase(3)),
            'barcode' => null,
            'quantity' => $quantity,
            'available' => $quantity,
            'in_order' => 0,
            'due' => 0,
            'minimum_level' => $this->faker->numberBetween(0, 10),
            'purchase_price' => 0.0,
            'retail_price' => 0.0,
            'tax_rate' => 20.0,
            'weight' => $this->faker->numberBetween(1, 5000) / 1000,
            'weight_unit' => 'kg',
            'height' => $this->faker->numberBetween(1, 400) / 10,
            'width' => $this->faker->numberBetween(1, 400) / 10,
            'depth' => $this->faker->numberBetween(1, 400) / 10,
            'is_composite' => false,
            'linnworks_created_at' => self::BASE_TIMESTAMP,
            'created_at' => self::BASE_TIMESTAMP,
            'updated_at' => self::BASE_TIMESTAMP,
            'category_id' => '00000000-0000-0000-0000-000000000000',
            'category_name' => 'Default',
            'jit' => false,
            'is_archived' => false,
            'is_logically_deleted' => false,
        ];
    }
}
