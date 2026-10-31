<?php

declare(strict_types=1);

namespace Database\Factories\ProductSlice;

use App\Infrastructure\Shopwired\Models\FilterGroupDefinitionModel;
use Override;

/**
 * @extends AbstractSliceRowFactory<FilterGroupDefinitionModel>
 */
final class FilterGroupRowFactory extends AbstractSliceRowFactory
{
    protected $model = FilterGroupDefinitionModel::class;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        return [
            'id' => $this->faker->uuid(),
            'external_id' => 0,
            'title' => '',
            'option_no' => 0,
            'sort_order' => 0,
            'created_at' => self::BASE_TIMESTAMP,
            'updated_at' => self::BASE_TIMESTAMP,
        ];
    }
}
