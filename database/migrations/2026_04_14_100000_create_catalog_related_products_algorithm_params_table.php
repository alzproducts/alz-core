<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the catalog.related_products_algorithm_params table.
 *
 * Stores versioned algorithm parameters for the related products algorithm.
 * Each row represents one algorithm version. At most one row may be active
 * at any time, enforced by a partial unique index on (is_active = true).
 *
 * Enables the pg_trgm extension required for the similarity() function
 * used in the algorithm SQL.
 *
 * Seeded with v1 using the calibrated defaults from tmp/related-products-test.sql.
 */
return new class extends Migration {
    public function up(): void
    {
        // Required by similarity() in the related products algorithm SQL
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::create('catalog.related_products_algorithm_params', static function (Blueprint $table): void {
            $table->id();
            $table->smallInteger('algorithm_version')->unique();

            $table->decimal('category_weight', 5, 3)->comment('Category Jaccard similarity weight');
            $table->decimal('title_weight', 5, 3)->comment('Title trigram similarity weight');
            $table->decimal('popularity_weight', 5, 3)->comment('Popularity score weight');

            $table->smallInteger('max_results')->comment('Maximum related products per product');
            $table->decimal('min_content_score', 5, 3)->comment('Minimum combined content score threshold (cat + title)');
            $table->decimal('default_popularity', 5, 3)->comment('Fallback popularity score for unranked products');

            $table->boolean('exclude_compare_list')->default(true)
                ->comment('Whether to exclude products listed in compare_list custom field');

            $table->boolean('is_active')->default(false);
            $table->text('notes')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        // Business invariants enforced at DB level
        DB::statement('
            ALTER TABLE catalog.related_products_algorithm_params
                ADD CONSTRAINT ck_related_products_params_positive_weights
                    CHECK (category_weight > 0 AND title_weight > 0 AND popularity_weight > 0),
                ADD CONSTRAINT ck_related_products_params_max_results_range
                    CHECK (max_results BETWEEN 2 AND 20)
        ');

        // At most one active config row at any time
        DB::statement('
            CREATE UNIQUE INDEX idx_related_products_params_single_active
                ON catalog.related_products_algorithm_params (is_active)
                WHERE is_active = true
        ');

        // Seed v1 with calibrated defaults from tmp/related-products-test.sql
        DB::table('catalog.related_products_algorithm_params')->insert([
            'algorithm_version'  => 1,
            'category_weight'    => 0.450,
            'title_weight'       => 0.350,
            'popularity_weight'  => 0.200,
            'max_results'        => 8,
            'min_content_score'  => 0.100,
            'default_popularity' => 1.000,
            'exclude_compare_list' => true,
            'is_active'          => true,
            'notes'              => 'Initial version — category Jaccard + title trigram + popularity blend, with pin/exclusion/self-exclusion support',
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog.related_products_algorithm_params');
    }
};
