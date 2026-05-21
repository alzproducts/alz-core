<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the catalog.credit_product_popularity_ranking_config table.
 *
 * Mirrors catalog.product_popularity_ranking_config but for credit-only sales
 * with overlapping windows, turnover-heavy weighting, and a 3-tier output system
 * (tier_1_size / tier_2_size) replacing the boolean best-seller flag.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('catalog.credit_product_popularity_ranking_config', static function (Blueprint $table): void {
            $table->smallInteger('algorithm_version')->primary();

            // Stored as INTERVAL after post-create ALTER TABLE (Blueprint has no INTERVAL type)
            $table->string('main_period_interval');
            $table->string('recent_period_interval');

            $table->decimal('w_main', 5, 3);
            $table->decimal('w_recent', 5, 3);
            $table->decimal('w_qty', 5, 3);
            $table->decimal('w_turnover', 5, 3);

            $table->smallInteger('max_rank');
            $table->smallInteger('tier_1_size');
            $table->smallInteger('tier_2_size');
            $table->boolean('is_active')->default(false);
            $table->text('notes')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        // Convert string columns to PostgreSQL INTERVAL type
        DB::statement('
            ALTER TABLE catalog.credit_product_popularity_ranking_config
                ALTER COLUMN main_period_interval   TYPE INTERVAL USING main_period_interval::interval,
                ALTER COLUMN recent_period_interval TYPE INTERVAL USING recent_period_interval::interval
        ');

        // Business invariants enforced at DB level
        DB::statement('
            ALTER TABLE catalog.credit_product_popularity_ranking_config
                ADD CONSTRAINT ck_credit_popularity_config_recent_lt_main   CHECK (recent_period_interval < main_period_interval),
                ADD CONSTRAINT ck_credit_popularity_config_positive_weights CHECK (w_main > 0 AND w_recent > 0 AND w_qty > 0 AND w_turnover > 0),
                ADD CONSTRAINT ck_credit_popularity_config_max_rank_range   CHECK (max_rank BETWEEN 2 AND 100),
                ADD CONSTRAINT ck_credit_popularity_config_tier_sizes       CHECK (tier_1_size > 0 AND tier_2_size > tier_1_size)
        ');

        // At most one active config row at any time
        DB::statement('
            CREATE UNIQUE INDEX idx_credit_popularity_config_single_active
                ON catalog.credit_product_popularity_ranking_config (is_active)
                WHERE is_active = true
        ');

        // Seed v1 with calibrated defaults from tmp/credit-customer-popularity.sql
        DB::table('catalog.credit_product_popularity_ranking_config')->insert([
            'algorithm_version'      => 1,
            'main_period_interval'   => '12 months',
            'recent_period_interval' => '3 months',
            'w_main'                 => 0.850,
            'w_recent'               => 0.150,
            'w_qty'                  => 0.300,
            'w_turnover'             => 0.700,
            'max_rank'               => 5,
            'tier_1_size'            => 15,
            'tier_2_size'            => 50,
            'is_active'              => true,
            'notes'                  => 'Initial version — overlapping 12mo/3mo windows, credit-only sellers, turnover-heavy blend, 3-tier output',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog.credit_product_popularity_ranking_config');
    }
};
