<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the catalog.product_popularity_snapshots table.
 *
 * Append-only history of every weekly ranking run. One row per product
 * per snapshot date. Composite PK on (snapshot_date, parent_external_id)
 * ensures duplicate runs fail loudly rather than silently overwriting.
 *
 * The algorithm_version FK links every snapshot row back to the exact config
 * used to produce it, enabling apples-to-apples historical comparison even
 * if the algorithm changes between runs.
 *
 * timestampsTz() columns receive DEFAULT NOW() via post-create ALTER TABLE
 * so the INSERT...SELECT in the repository does not need to populate them.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('catalog.product_popularity_snapshots', static function (Blueprint $table): void {
            $table->date('snapshot_date');
            $table->smallInteger('algorithm_version');
            $table->integer('parent_external_id');

            $table->string('sku')->nullable();
            $table->text('title')->nullable();
            $table->boolean('is_active')->nullable();

            // Bounded by max_rank (2..100) — smallint is sufficient
            $table->smallInteger('calculated_sort_order');
            // Matches shopwired.products.sort_order column type (integer)
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
            // trend = recent_score - main_score, range ~-11.00..+11.00
            $table->decimal('trend', 5, 2);

            $table->timestampsTz();

            $table->primary(['snapshot_date', 'parent_external_id']);

            $table->foreign('algorithm_version')
                ->references('algorithm_version')
                ->on('catalog.product_popularity_ranking_config')
                ->restrictOnDelete();

            // Product history over time (e.g. "how has product X's rank moved?")
            $table->index(['parent_external_id', 'snapshot_date'], 'idx_popularity_snapshots_product_history');

            // Filter by algorithm version (apples-to-apples comparison across versions)
            $table->index(['algorithm_version', 'snapshot_date'], 'idx_popularity_snapshots_by_version');
        });

        // Blueprint's timestampsTz() does not apply DEFAULT NOW() — add it so the
        // INSERT...SELECT in the repository omits these columns and they auto-populate.
        DB::statement('
            ALTER TABLE catalog.product_popularity_snapshots
                ALTER COLUMN created_at SET DEFAULT NOW(),
                ALTER COLUMN updated_at SET DEFAULT NOW()
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog.product_popularity_snapshots');
    }
};
