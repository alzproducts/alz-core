<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * View that flags products whose `vat_relief` boolean has drifted from the
 * ShopWired "Eligible for VAT Relief?" filter (optionNo 2).
 *
 * `vat_relief = TRUE`  → desired filter value `['Yes']`
 * `vat_relief = FALSE` → desired filter value `[]` (filter absent)
 * `vat_relief = NULL`  → skipped — unknown / not yet synced from product embed
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW catalog.products_with_changed_vat_relief_filters AS
            WITH with_desired AS (
                SELECT
                    p.external_id AS product_id,
                    p.filters,
                    CASE
                        WHEN p.vat_relief THEN ARRAY['Yes']
                        ELSE ARRAY[]::text[]
                    END AS desired_filter_values
                FROM shopwired.products p
                WHERE p.vat_relief IS NOT NULL
            )
            SELECT
                product_id,
                desired_filter_values
            FROM with_desired
            -- 2 = FilterGroupOptionNo::VatRelief (keep in sync with FilterGroupOptionNo.php)
            WHERE COALESCE(filters->'2', '[]'::jsonb)
                  IS DISTINCT FROM to_jsonb(desired_filter_values)
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS catalog.products_with_changed_vat_relief_filters');
    }
};
