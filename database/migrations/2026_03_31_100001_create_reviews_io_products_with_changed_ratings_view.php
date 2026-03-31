<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Encapsulates the cross-schema query that finds products whose aggregated
 * Reviews.io ratings differ from the custom fields stored in ShopWired.
 *
 * Moving this to a Postgres view keeps the repository method trivial and
 * makes the query independently testable/optimizable.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW reviews_io.products_with_changed_ratings AS
            SELECT
                product_skus.product_id,
                ROUND(SUM(r.average_rating * r.num_ratings) / NULLIF(SUM(r.num_ratings), 0), 4) as new_average,
                COALESCE(SUM(r.num_ratings), 0)::int as new_count
            FROM (
                SELECT external_id as product_id, sku
                FROM shopwired.products
                WHERE sku IS NOT NULL AND sku != ''
                UNION ALL
                SELECT product_external_id as product_id, sku
                FROM shopwired.product_variations
                WHERE sku IS NOT NULL AND sku != ''
            ) product_skus
            LEFT JOIN reviews_io.product_ratings r ON r.sku = product_skus.sku
            JOIN shopwired.products p ON p.external_id = product_skus.product_id
            GROUP BY product_skus.product_id, p.custom_fields
            HAVING
                COALESCE(ROUND(SUM(r.average_rating * r.num_ratings) / NULLIF(SUM(r.num_ratings), 0), 4), 0)
                != COALESCE((p.custom_fields->>'average_rating')::numeric, 0)
                OR COALESCE(SUM(r.num_ratings), 0) != COALESCE((p.custom_fields->>'num_ratings')::int, 0)
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS reviews_io.products_with_changed_ratings');
    }
};
