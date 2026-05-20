<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Repositories;

/**
 * Drift query for margin-tier labels.
 *
 * CROSS JOIN against the single-row `catalog.margin_tier_thresholds` table
 * inlines the thresholds without PHP bindings. `IS DISTINCT FROM` is NULL-safe —
 * products with a NULL `current_label` and any non-NULL `target_label` naturally
 * appear (first-run backfill).
 */
final class MarginTierDriftSql
{
    public const string SQL = <<<'SQL'
        WITH classified AS (
            SELECT
                pv.external_id,
                pv.custom_fields->>'custom_label_1' AS current_label,
                CASE
                    WHEN pv.net_margin_single_unit_min IS NULL THEN '4 - Unknown margin'
                    WHEN (pv.net_margin_single_unit_min + pv.net_margin_single_unit_max) / 2 <  t.low_max_pct      THEN '1 - Low margin'
                    WHEN (pv.net_margin_single_unit_min + pv.net_margin_single_unit_max) / 2 <  t.standard_max_pct THEN '2 - Standard margin'
                    ELSE                                                                                                '3 - High margin'
                END AS target_label
            FROM catalog.products_view pv
            CROSS JOIN catalog.margin_tier_thresholds t
            WHERE pv.is_active = true
        )
        SELECT external_id, target_label
        FROM classified
        WHERE current_label IS DISTINCT FROM target_label
        SQL;
}
