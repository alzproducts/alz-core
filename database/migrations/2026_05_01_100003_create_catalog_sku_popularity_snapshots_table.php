<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the catalog.sku_popularity_snapshots table.
 *
 * Append-only history of every weekly SKU ranking run. One row per SKU
 * per snapshot date. Composite PK on (snapshot_date, live_sku) ensures
 * duplicate runs fail loudly.
 *
 * Mirrors catalog.product_popularity_snapshots with SKU-level identity:
 *   - live_sku (VARCHAR) replaces parent_external_id as the primary identity
 *   - parent_external_id kept as reference column
 *   - variation_external_id added (NULL for non-varying product SKUs)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('catalog.sku_popularity_snapshots', static function (Blueprint $table): void {
            $table->date('snapshot_date');
            $table->smallInteger('algorithm_version');
            $table->string('live_sku');

            $table->integer('parent_external_id');
            $table->integer('variation_external_id')->nullable();
            $table->text('title')->nullable();
            $table->boolean('is_active')->nullable();

            $table->smallInteger('calculated_sort_order');
            $table->integer('current_sort_order')->nullable();

            $table->decimal('main_qty', 12, 0);
            $table->decimal('main_turnover', 14, 2);
            $table->decimal('recent_qty', 12, 0);
            $table->decimal('recent_turnover', 14, 2);

            $table->decimal('main_qty_rank', 5, 2);
            $table->decimal('main_turnover_rank', 5, 2);
            $table->decimal('recent_qty_rank', 5, 2);
            $table->decimal('recent_turnover_rank', 5, 2);

            $table->decimal('main_score', 5, 2);
            $table->decimal('recent_score', 5, 2);
            $table->decimal('final_score', 5, 2);
            $table->decimal('trend', 5, 2);

            $table->timestampsTz();

            $table->primary(['snapshot_date', 'live_sku']);

            $table->foreign('algorithm_version')
                ->references('algorithm_version')
                ->on('catalog.sku_popularity_ranking_config')
                ->restrictOnDelete();

            $table->index(['live_sku', 'snapshot_date'], 'idx_sku_popularity_snapshots_sku_history');

            $table->index(['algorithm_version', 'snapshot_date'], 'idx_sku_popularity_snapshots_by_version');
        });

        DB::statement('
            ALTER TABLE catalog.sku_popularity_snapshots
                ALTER COLUMN created_at SET DEFAULT NOW(),
                ALTER COLUMN updated_at SET DEFAULT NOW()
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog.sku_popularity_snapshots');
    }
};
