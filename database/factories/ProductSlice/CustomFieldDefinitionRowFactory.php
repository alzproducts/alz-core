<?php

declare(strict_types=1);

namespace Database\Factories\ProductSlice;

use App\Infrastructure\Shopwired\Models\CustomFieldDefinitionModel;
use Override;

/**
 * @extends AbstractSliceRowFactory<CustomFieldDefinitionModel>
 */
final class CustomFieldDefinitionRowFactory extends AbstractSliceRowFactory
{
    protected $model = CustomFieldDefinitionModel::class;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        return [
            'id' => $this->faker->uuid(),
            'external_id' => 0,
            'name' => '',
            'type' => 'text',
            'label' => \ucfirst($this->phrase(2)),
            'item_type' => 'product',
            'sort_order' => 0,
            'allowed_values' => null,
            'created_at' => self::BASE_TIMESTAMP,
            'updated_at' => self::BASE_TIMESTAMP,
        ];
    }
}
