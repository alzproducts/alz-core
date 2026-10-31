<?php

declare(strict_types=1);

namespace Database\Factories\ProductSlice;

use App\Infrastructure\Linnworks\Models\StockItemSupplierModel;
use Override;

/**
 * @extends AbstractSliceRowFactory<StockItemSupplierModel>
 */
final class StockItemSupplierRowFactory extends AbstractSliceRowFactory
{
    protected $model = StockItemSupplierModel::class;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        return [
            'id' => $this->faker->uuid(),
            'stock_item_id' => '',
            'supplier_id' => '',
            'supplier_name' => '',
            'code' => $this->faker->bothify('??-####'),
            'supplier_barcode' => null,
            'purchase_price' => 0.0,
            'is_default' => false,
            'lead_time' => $this->faker->numberBetween(1, 30),
            'supplier_currency' => 'GBP',
            'min_price' => null,
            'max_price' => null,
            'average_price' => null,
            'created_at' => self::BASE_TIMESTAMP,
            'updated_at' => self::BASE_TIMESTAMP,
            'average_lead_time' => null,
            'supplier_min_order_qty' => null,
            'supplier_pack_size' => null,
        ];
    }
}
