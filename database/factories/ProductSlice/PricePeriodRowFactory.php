<?php

declare(strict_types=1);

namespace Database\Factories\ProductSlice;

use App\Infrastructure\Operations\Models\PricePeriodModel;
use Override;

/**
 * @extends AbstractSliceRowFactory<PricePeriodModel>
 */
final class PricePeriodRowFactory extends AbstractSliceRowFactory
{
    protected $model = PricePeriodModel::class;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        return [
            'id' => $this->faker->uuid(),
            'sku' => '',
            'base_price_gross' => 0.0,
            'sale_price_gross' => null,
            'effective_price_gross' => 0.0,
            'price_has_tax' => true,
            'effective_from' => self::BASE_TIMESTAMP,
            'effective_to' => null,
            'created_at' => self::BASE_TIMESTAMP,
        ];
    }
}
