<?php

declare(strict_types=1);

namespace Database\Factories\ProductSlice;

use App\Infrastructure\Catalog\Models\CostPriceChangeLogModel;
use Override;

/**
 * @extends AbstractSliceRowFactory<CostPriceChangeLogModel>
 */
final class CostPriceChangeRowFactory extends AbstractSliceRowFactory
{
    protected $model = CostPriceChangeLogModel::class;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        return [
            'id' => $this->faker->uuid(),
            'sku' => '',
            'supplier_id' => '',
            'supplier_name' => '',
            'old_cost_price' => 0.0,
            'new_cost_price' => 0.0,
            'changed_at' => self::BASE_TIMESTAMP,
        ];
    }
}
