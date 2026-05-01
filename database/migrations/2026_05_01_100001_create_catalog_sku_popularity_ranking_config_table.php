<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the catalog.sku_popularity_ranking_config table.
 *
 * Stores versioned algorithm parameters for the SKU-level popularity ranking.
 * Mirrors catalog.product_popularity_ranking_config exactly — independent
 * calibration per pipeline.
 *
 * Seeded with v1 using the same defaults as the product pipeline.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('catalog.sku_popularity_ranking_config', static function (Blueprint $table): void {
            $table->smallInteger('algorithm_version')->primary();

            $table->string('main_period_interval');
            $table->string('recent_period_interval');

            $table->decimal('w_main', 5, 3);
            $table->decimal('w_recent', 5, 3);
            $table->decimal('w_qty', 5, 3);
            $table->decimal('w_turnover', 5, 3);

            $table->smallInteger('max_rank');
            $table->boolean('is_active')->default(false);
            $table->text('notes')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('
            ALTER TABLE catalog.sku_popularity_ranking_config
                ALTER COLUMN main_period_interval   TYPE INTERVAL USING main_period_interval::interval,
                ALTER COLUMN recent_period_interval TYPE INTERVAL USING recent_period_interval::interval
        ');

        DB::statement('
            ALTER TABLE catalog.sku_popularity_ranking_config
                ADD CONSTRAINT ck_sku_popularity_config_recent_lt_main   CHECK (recent_period_interval < main_period_interval),
                ADD CONSTRAINT ck_sku_popularity_config_positive_weights CHECK (w_main > 0 AND w_recent > 0 AND w_qty > 0 AND w_turnover > 0),
                ADD CONSTRAINT ck_sku_popularity_config_max_rank_range   CHECK (max_rank BETWEEN 2 AND 100)
        ');

        DB::statement('
            CREATE UNIQUE INDEX idx_sku_popularity_config_single_active
                ON catalog.sku_popularity_ranking_config (is_active)
                WHERE is_active = true
        ');

        DB::table('catalog.sku_popularity_ranking_config')->insert([
            'algorithm_version'      => 1,
            'main_period_interval'   => '12 months',
            'recent_period_interval' => '2 months',
            'w_main'                 => 0.700,
            'w_recent'               => 0.300,
            'w_qty'                  => 0.500,
            'w_turnover'             => 0.500,
            'max_rank'               => 12,
            'is_active'              => true,
            'notes'                  => 'Initial version — mirrors product pipeline v1 defaults',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog.sku_popularity_ranking_config');
    }
};
