<?php

declare(strict_types=1);

namespace Database\Factories\ProductSlice;

use Override;

final class SkuPopularitySnapshotRowFactory extends AbstractPopularitySnapshotRowFactory
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function definition(): array
    {
        return [
            'snapshot_date' => '',
            'algorithm_version' => 1,
            'live_sku' => '',
            'parent_external_id' => 0,
            'variation_external_id' => null,
            'title' => null,
            'is_active' => true,
            'calculated_sort_order' => 0,
            'current_sort_order' => null,
            ...$this->metrics(),
            'created_at' => self::BASE_TIMESTAMP,
            'updated_at' => self::BASE_TIMESTAMP,
        ];
    }
}
