<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Repositories;

/**
 * SQL for the related products algorithm query.
 *
 * Split across focused static methods to stay within class and method length limits.
 * The 7 ?-bindings match this param order:
 * category_weight, title_weight, popularity_weight, max_results, min_content_score,
 * default_popularity, exclude_compare_list.
 */
final class RelatedProductsAlgorithmSql
{
    /** Assemble the full related products algorithm query from CTE fragments. */
    public static function buildSql(): string
    {
        return 'WITH '
            . self::initCtes()
            . self::activeProductsCte()
            . self::exclusionCte()
            . self::pinsCtes()
            . self::selfExcludedCtes()
            . RelatedProductsPairScoringSql::ctes()
            . self::combinedNormalBranches()
            . self::combinedSelfExcludedBranch()
            . 'SELECT product_external_id, related_external_id, position
               FROM combined
               ORDER BY product_external_id, position';
    }

    /** params + max_pop + excluded_categories CTEs */
    private static function initCtes(): string
    {
        return <<<'SQL'
        params AS (
            SELECT ?::numeric AS category_weight, ?::numeric AS title_weight, ?::numeric AS popularity_weight,
                ?::smallint AS max_results, ?::numeric AS min_content_score,
                ?::numeric AS default_popularity, ?::boolean AS exclude_compare_list
        ), max_pop AS (
            SELECT COALESCE(MAX(final_score), 1)::numeric AS max_score
            FROM catalog.product_popularity_ranking_latest
        ), excluded_categories AS (
            SELECT ARRAY_AGG(external_id) AS ids FROM shopwired.categories
            WHERE title ILIKE '%gift%' OR title ILIKE '%best%' OR title ILIKE '%sale%'
               OR title IN ('New', 'All Products', 'All Care Home Products')
        ),

        SQL;
    }

    /** active_products CTE — filters discontinued+out-of-stock, strips excluded categories */
    private static function activeProductsCte(): string
    {
        return <<<'SQL'
        active_products AS (
            SELECT p.id, p.external_id, p.title, p.custom_fields,
                COALESCE(pop.final_score, par.default_popularity)::numeric AS final_score,
                COALESCE(ARRAY(
                    SELECT cat_id::int FROM jsonb_array_elements_text(p.category_ids) AS cat_id
                    WHERE cat_id::int != ALL(COALESCE(ec.ids, ARRAY[]::int[]))
                ), ARRAY[]::int[]) AS cat_ids
            FROM catalog.products_view p
            CROSS JOIN params par
            LEFT JOIN catalog.product_popularity_ranking_latest pop
                ON pop.parent_external_id = p.external_id
            LEFT JOIN (
                SELECT product_id, SUM(COALESCE(stock, 0))::int AS variant_stock
                FROM catalog.product_variations_view GROUP BY product_id
            ) vs ON vs.product_id = p.id
            CROSS JOIN excluded_categories ec
            WHERE p.is_active = true
              AND NOT (p.custom_fields->>'discontinued' IS NOT NULL
                       AND p.custom_fields->>'discontinued' != ''
                       AND (COALESCE(p.stock, 0) + COALESCE(vs.variant_stock, 0)) <= 0)
        ),

        SQL;
    }

    /** product_exclusions CTE — merges series_products, related_exclusions, compare_list */
    private static function exclusionCte(): string
    {
        return <<<'SQL'
        product_exclusions AS MATERIALIZED (
            SELECT ap.id AS product_id,
                (COALESCE(CASE WHEN jsonb_typeof(ap.custom_fields->'series_products') = 'array'
                     THEN ARRAY(SELECT jsonb_array_elements_text(ap.custom_fields->'series_products')::int)
                     ELSE ARRAY[]::int[] END, ARRAY[]::int[])
                 || COALESCE(CASE WHEN jsonb_typeof(ap.custom_fields->'related_exclusions') = 'array'
                     THEN ARRAY(SELECT jsonb_array_elements_text(ap.custom_fields->'related_exclusions')::int)
                     ELSE ARRAY[]::int[] END, ARRAY[]::int[])
                 || CASE WHEN par.exclude_compare_list THEN
                     COALESCE(CASE WHEN jsonb_typeof(ap.custom_fields->'compare_list') = 'array'
                         THEN ARRAY(SELECT jsonb_array_elements_text(ap.custom_fields->'compare_list')::int)
                         ELSE ARRAY[]::int[] END, ARRAY[]::int[])
                    ELSE ARRAY[]::int[] END
                ) AS excluded_external_ids
            FROM active_products ap CROSS JOIN params par
        ),

        SQL;
    }

    /** product_pins + resolved_pins + pin_counts CTEs */
    private static function pinsCtes(): string
    {
        return <<<'SQL'
        product_pins AS (
            SELECT ap.id AS product_id, pin_ext_id::int AS pinned_external_id, pin_ord AS pin_position
            FROM active_products ap,
            LATERAL jsonb_array_elements_text(
                CASE WHEN jsonb_typeof(ap.custom_fields->'related_pins') = 'array'
                     THEN ap.custom_fields->'related_pins' ELSE '[]'::jsonb END
            ) WITH ORDINALITY AS pins(pin_ext_id, pin_ord)
        ), resolved_pins AS (
            SELECT pp.product_id, b.id AS pinned_product_id,
                b.external_id AS pinned_external_id, pp.pin_position::smallint AS position
            FROM product_pins pp INNER JOIN active_products b ON b.external_id = pp.pinned_external_id
        ), pin_counts AS (
            SELECT product_id, COUNT(*)::int AS num_pins FROM resolved_pins GROUP BY product_id
        ),

        SQL;
    }

    /** self_excluded_products + reverse_pinners CTEs */
    private static function selfExcludedCtes(): string
    {
        return <<<'SQL'
        self_excluded_products AS (
            SELECT id, external_id FROM active_products
            WHERE custom_fields->>'related_exclude_self' IS NOT NULL
              AND custom_fields->>'related_exclude_self' != ''
        ), reverse_pinners AS (
            SELECT se.id AS product_id, pinner.external_id AS related_external_id,
                ROW_NUMBER() OVER (PARTITION BY se.id ORDER BY pinner.final_score DESC)::smallint AS position
            FROM self_excluded_products se
            INNER JOIN active_products pinner
                ON jsonb_typeof(pinner.custom_fields->'related_pins') = 'array'
                AND EXISTS (SELECT 1 FROM jsonb_array_elements_text(pinner.custom_fields->'related_pins') pin_id
                    WHERE pin_id::int = se.external_id)
        ),

        SQL;
    }

    /** combined CTE — header + normal products branches (pins and algorithmic) */
    private static function combinedNormalBranches(): string
    {
        return <<<'SQL'
        combined AS (
            SELECT a.id AS product_id, a.external_id AS product_external_id,
                rp.pinned_external_id AS related_external_id, rp.position
            FROM resolved_pins rp INNER JOIN active_products a ON a.id = rp.product_id
            WHERE NOT EXISTS (SELECT 1 FROM self_excluded_products se WHERE se.id = a.id)

            UNION ALL

            SELECT ar.product_id, ar.product_external_id, ar.related_external_id, ar.position
            FROM algorithmic_results ar
            WHERE NOT EXISTS (SELECT 1 FROM self_excluded_products se WHERE se.id = ar.product_id)

            UNION ALL

        SQL;
    }

    /** combined CTE — self-excluded products branch + closing paren */
    private static function combinedSelfExcludedBranch(): string
    {
        return <<<'SQL'
            SELECT product_id, product_external_id, related_external_id, rn AS position
            FROM (
                SELECT product_id, product_external_id, related_external_id,
                    ROW_NUMBER() OVER (PARTITION BY product_id ORDER BY is_pinned DESC, position)::smallint AS rn
                FROM (
                    SELECT a.id AS product_id, a.external_id AS product_external_id,
                        rp.pinned_external_id AS related_external_id, rp.position, true AS is_pinned
                    FROM resolved_pins rp INNER JOIN active_products a ON a.id = rp.product_id
                    WHERE EXISTS (SELECT 1 FROM self_excluded_products se WHERE se.id = a.id)
                    UNION ALL
                    SELECT a.id AS product_id, a.external_id AS product_external_id,
                        rv.related_external_id, rv.position, false AS is_pinned
                    FROM reverse_pinners rv INNER JOIN active_products a ON a.id = rv.product_id
                    WHERE NOT EXISTS (SELECT 1 FROM resolved_pins rp
                        WHERE rp.product_id = rv.product_id
                          AND rp.pinned_external_id = rv.related_external_id)
                ) se_all
            ) se_ranked CROSS JOIN params par
            WHERE rn <= par.max_results
        )

        SQL;
    }
}
