<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Repositories;

/**
 * Drift query for credit-tier labels (`custom_label_0`).
 *
 * Compares the latest credit-tier snapshot against live state in
 * `catalog.products_view`. INNER JOIN: once the weekly snapshot has run,
 * the snapshot row set contains every product, so every active
 * `products_view` row has a match — products without credit sales appear
 * with `credit_tier = NULL` (target_label = NULL), naturally clearing the
 * label if previously set.
 *
 * Pre-first-snapshot, `credit_product_popularity_ranking_latest` is empty,
 * the INNER JOIN returns 0 rows, and the daily label sync silently no-ops
 * until the first weekly snapshot lands.
 *
 * `NULLIF(..., '')` is critical: MergesCustomFieldsTrait writes null as '',
 * so reading back gets '' not NULL. Without NULLIF, cleared labels would
 * re-dispatch forever (`'' IS DISTINCT FROM NULL = true`).
 *
 * `IS DISTINCT FROM` is NULL-safe — handles set / change / clear / no-op.
 */
final class CreditTierDriftSql
{
    public const string SQL = <<<'SQL'
        WITH classified AS (
            SELECT
                pv.external_id,
                NULLIF(pv.custom_fields->>'custom_label_0', '') AS current_label,
                latest.credit_tier AS target_label
            FROM catalog.products_view pv
            JOIN catalog.credit_product_popularity_ranking_latest latest
                ON latest.parent_external_id = pv.external_id
            WHERE pv.is_active = true
        )
        SELECT external_id, target_label
        FROM classified
        WHERE current_label IS DISTINCT FROM target_label
        SQL;
}
