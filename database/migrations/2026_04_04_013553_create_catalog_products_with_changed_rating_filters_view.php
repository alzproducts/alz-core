<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cross-schema view that computes desired rating filter values per product
 * and returns only those where the current filters differ from the desired state.
 *
 * Aggregates review ratings across product SKUs and variation SKUs,
 * applies thresholds (4.0 and 4.5), and diffs against current filter JSONB.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW catalog.products_with_changed_rating_filters AS
            WITH product_averages AS (
                SELECT
                    product_skus.product_id,
                    ROUND(
                        SUM(r.average_rating * r.num_ratings)
                        / NULLIF(SUM(r.num_ratings), 0),
                        4
                    ) AS weighted_average
                FROM (
                    SELECT external_id AS product_id, sku
                    FROM shopwired.products
                    WHERE sku IS NOT NULL AND sku != ''
                    UNION ALL
                    SELECT product_external_id AS product_id, sku
                    FROM shopwired.product_variations
                    WHERE sku IS NOT NULL AND sku != ''
                ) product_skus
                LEFT JOIN reviews_io.product_ratings r ON r.sku = product_skus.sku
                GROUP BY product_skus.product_id
            ),
            with_desired AS (
                SELECT
                    pa.product_id,
                    p.filters,
                    CASE
                        WHEN pa.weighted_average >= 4.5 THEN ARRAY['4', '4.5']
                        WHEN pa.weighted_average >= 4.0 THEN ARRAY['4']
                        ELSE ARRAY[]::text[]
                    END AS desired_filter_values
                FROM product_averages pa
                JOIN shopwired.products p ON p.external_id = pa.product_id
            )
            SELECT
                product_id,
                desired_filter_values
            FROM with_desired
            -- 15 = FilterGroupOptionNo::CustomerRating (keep in sync with FilterGroupOptionNo.php)
            WHERE COALESCE(filters->'15', '[]'::jsonb)
                  IS DISTINCT FROM to_jsonb(desired_filter_values)
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS catalog.products_with_changed_rating_filters');
    }
};
