<?php

declare(strict_types=1);

namespace Database\Factories\ProductSlice;

use Illuminate\Database\Eloquent\Model;

/**
 * Popularity snapshot tables are model-less (composite primary key): rows go through a raw query-builder insert.
 *
 * @extends AbstractSliceRowFactory<Model>
 */
abstract class AbstractPopularitySnapshotRowFactory extends AbstractSliceRowFactory
{
    /**
     * @return array<string, float>
     */
    final protected function metrics(): array
    {
        return [
            'main_qty' => (float) $this->faker->numberBetween(0, 400),
            'main_turnover' => $this->faker->numberBetween(0, 2_000_000) / 100,
            'recent_qty' => (float) $this->faker->numberBetween(0, 60),
            'recent_turnover' => $this->faker->numberBetween(0, 300_000) / 100,
            'main_qty_rank' => $this->ratio(),
            'main_turnover_rank' => $this->ratio(),
            'recent_qty_rank' => $this->ratio(),
            'recent_turnover_rank' => $this->ratio(),
            'main_score' => $this->ratio(),
            'recent_score' => $this->ratio(),
            'final_score' => $this->ratio(),
            'trend' => $this->faker->numberBetween(-10_000, 10_000) / 10_000,
        ];
    }

    private function ratio(): float
    {
        return $this->faker->numberBetween(0, 10_000) / 10_000;
    }
}
