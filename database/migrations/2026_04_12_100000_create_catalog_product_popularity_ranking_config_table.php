<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the catalog.product_popularity_ranking_config table.
 *
 * Stores versioned algorithm parameters for the product popularity ranking.
 * Each row represents one algorithm version. At most one row may be active
 * at any time, enforced by a partial unique index on (is_active = true).
 *
 * The INTERVAL columns (main_period_interval, recent_period_interval) are
 * initially created as VARCHAR then converted via ALTER TABLE — Blueprint
 * has no native INTERVAL type.
 *
 * Seeded with v1 using the calibrated defaults from tmp/product_ranking.sql.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('catalog.product_popularity_ranking_config', static function (Blueprint $table): void {
            $table->smallInteger('algorithm_version')->primary();

            // Stored as INTERVAL after post-create ALTER TABLE (Blueprint has no INTERVAL type)
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

        // Convert string columns to PostgreSQL INTERVAL type
        DB::statement('
            ALTER TABLE catalog.product_popularity_ranking_config
                ALTER COLUMN main_period_interval   TYPE INTERVAL USING main_period_interval::interval,
                ALTER COLUMN recent_period_interval TYPE INTERVAL USING recent_period_interval::interval
        ');

        // Business invariants enforced at DB level
        DB::statement('
            ALTER TABLE catalog.product_popularity_ranking_config
                ADD CONSTRAINT ck_popularity_config_recent_lt_main   CHECK (recent_period_interval < main_period_interval),
                ADD CONSTRAINT ck_popularity_config_positive_weights CHECK (w_main > 0 AND w_recent > 0 AND w_qty > 0 AND w_turnover > 0),
                ADD CONSTRAINT ck_popularity_config_max_rank_range   CHECK (max_rank BETWEEN 2 AND 100)
        ');

        // At most one active config row at any time
        DB::statement('
            CREATE UNIQUE INDEX idx_popularity_config_single_active
                ON catalog.product_popularity_ranking_config (is_active)
                WHERE is_active = true
        ');

        // Seed v1 with calibrated defaults from tmp/product_ranking.sql
        DB::table('catalog.product_popularity_ranking_config')->insert([
            'algorithm_version'      => 1,
            'main_period_interval'   => '12 months',
            'recent_period_interval' => '2 months',
            'w_main'                 => 0.700,
            'w_recent'               => 0.300,
            'w_qty'                  => 0.500,
            'w_turnover'             => 0.500,
            'max_rank'               => 12,
            'is_active'              => true,
            'notes'                  => 'Initial version — disjoint windows, sellers-only percentile, 50/50 qty+turnover blend',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog.product_popularity_ranking_config');
    }
};
