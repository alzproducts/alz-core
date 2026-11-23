<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Repositories;

/**
 * Pair scoring and ranking CTEs of the related products algorithm query.
 *
 * Every pair is ranked before the min_content_score filter, so a pair failing it still takes a rank
 * slot. Only pairs that could reach a product's top max_results are scored: pairs sharing a category
 * or a rare title trigram, plus any other pair whose total could beat that product's K-th scored
 * total. A pair in neither set has content below min_content_score, so its total is below
 * min_content_score + pop_score. A min_content_score of 0 scores every pair.
 *
 * intentional: big pair sets are never joined back to products by key; each side's columns ride
 * along from pair_products, so a misestimated CTE row count cannot pick a per-row nested loop.
 */
final class RelatedProductsPairScoringSql
{
    /** Pair CTEs, from title prefixes through algorithmic_results. */
    public static function ctes(): string
    {
        return self::titlePrefixCtes()
            . self::pairProductsCte()
            . self::candidatePairsCte()
            . self::extensionPairsCtes()
            . self::rankingCtes();
    }

    /**
     * Prefix filter for the title similarity join: two titles whose trigram similarity reaches t share
     * one of the rarest n - ceil(t * n) + 1 trigrams of each title. The 0.001 margin absorbs the float4
     * rounding of similarity(). Trigrams become integer ids so the per-pair overlap test skips collation.
     */
    private static function titlePrefixCtes(): string
    {
        return <<<'SQL'
        title_trigrams AS MATERIALIZED (
            SELECT ap.id AS product_id, COUNT(*) OVER (PARTITION BY t.trgm) AS df,
                DENSE_RANK() OVER (ORDER BY t.trgm) AS trgm_id
            FROM active_products ap CROSS JOIN LATERAL unnest(show_trgm(ap.title)) AS t(trgm)
        ), title_prefixes AS MATERIALIZED (
            SELECT rt.product_id,
                ARRAY_AGG(rt.trgm_id) FILTER (WHERE rt.rk <= rt.n - CEIL(tb.bound * rt.n) + 1) AS title_prefix
            FROM (
                SELECT product_id, trgm_id,
                    ROW_NUMBER() OVER (PARTITION BY product_id ORDER BY df, trgm_id) AS rk,
                    COUNT(*) OVER (PARTITION BY product_id) AS n
                FROM title_trigrams
            ) rt
            CROSS JOIN (
                SELECT CASE WHEN title_weight > 0 THEN min_content_score / title_weight - 0.001 ELSE 2 END AS bound
                FROM params
            ) tb
            GROUP BY rt.product_id
        ),

        SQL;
    }

    /** One row per active product carrying every column either side of a pair needs. */
    private static function pairProductsCte(): string
    {
        return <<<'SQL'
        pair_products AS MATERIALIZED (
            SELECT a.id, a.external_id, a.title, a.cat_ids, pe.excluded_external_ids,
                COALESCE(pb.pinned_product_ids, '{}') AS pinned_product_ids,
                COALESCE(pc.num_pins, 0) AS num_pins,
                (a.custom_fields->>'related_exclude_self' IS NULL
                    OR a.custom_fields->>'related_exclude_self' = '') AS rankable,
                (a.final_score / mp.max_score) * par.popularity_weight AS pop_score,
                COALESCE(tp.title_prefix, '{}'::bigint[]) AS title_prefix
            FROM active_products a CROSS JOIN params par CROSS JOIN max_pop mp
            LEFT JOIN product_exclusions pe ON pe.product_id = a.id
            LEFT JOIN pin_counts pc ON pc.product_id = a.id
            LEFT JOIN (
                SELECT product_id, ARRAY_AGG(pinned_product_id) AS pinned_product_ids
                FROM resolved_pins GROUP BY product_id
            ) pb ON pb.product_id = a.id
            LEFT JOIN title_prefixes tp ON tp.product_id = a.id
        ),

        SQL;
    }

    /** Pairs sharing a category or a title prefix trigram; every valid pair when min_content_score is 0. */
    private static function candidatePairsCte(): string
    {
        return 'candidate_pairs AS MATERIALIZED (' . self::scoredPairColumns()
            . ' FROM pair_products a CROSS JOIN pair_products b CROSS JOIN params par
            WHERE ' . self::validPairPredicate() . '
              AND (par.min_content_score <= 0 OR a.cat_ids && b.cat_ids OR a.title_prefix && b.title_prefix)
        ),
        ';
    }

    /** K-th best candidate total per product (K = max_results - pins), then the other pairs that could beat it. */
    private static function extensionPairsCtes(): string
    {
        return <<<'SQL'
        rank_thresholds AS MATERIALIZED (
            SELECT a.*, k.kth_total
            FROM pair_products a
            LEFT JOIN (
                SELECT c.product_id, c.total AS kth_total
                FROM (
                    SELECT product_id, num_pins, COALESCE(cat_score, 0) + title_score + pop_score AS total,
                        ROW_NUMBER() OVER (
                            PARTITION BY product_id
                            ORDER BY (COALESCE(cat_score, 0) + title_score + pop_score) DESC
                        ) AS rn
                    FROM candidate_pairs
                ) c CROSS JOIN params par
                WHERE c.rn = par.max_results - c.num_pins
            ) k ON k.product_id = a.id
        ),
        SQL
            . 'extension_pairs AS MATERIALIZED (' . self::scoredPairColumns()
            . ' FROM rank_thresholds a CROSS JOIN pair_products b CROSS JOIN params par
            WHERE par.min_content_score > 0
              AND (a.kth_total IS NULL OR par.min_content_score + b.pop_score > a.kth_total)
              AND ' . self::validPairPredicate() . '
              AND NOT (a.cat_ids && b.cat_ids) AND NOT (a.title_prefix && b.title_prefix)
        ),
        ';
    }

    /** ranked + algorithmic_results CTEs; related_external_id breaks exact score ties so reruns agree. */
    private static function rankingCtes(): string
    {
        return <<<'SQL'
        ranked AS (
            SELECT *, COALESCE(cat_score, 0) + title_score + pop_score AS total_score,
                ROW_NUMBER() OVER (
                    PARTITION BY product_id
                    ORDER BY (COALESCE(cat_score, 0) + title_score + pop_score) DESC, related_external_id
                ) AS rn
            FROM (SELECT * FROM candidate_pairs UNION ALL SELECT * FROM extension_pairs) scored_pairs
        ), algorithmic_results AS (
            SELECT r.product_id, r.product_external_id, r.related_external_id,
                (r.num_pins + r.rn)::smallint AS position
            FROM ranked r CROSS JOIN params par
            WHERE r.rn <= (par.max_results - r.num_pins)
              AND (COALESCE(r.cat_score, 0) + r.title_score) >= par.min_content_score
        ),

        SQL;
    }

    /** Category Jaccard + title trigram + popularity, for a pair of pair_products rows a and b. */
    private static function scoredPairColumns(): string
    {
        return <<<'SQL'
            SELECT a.id AS product_id, a.external_id AS product_external_id,
                b.external_id AS related_external_id, a.num_pins,
                COALESCE(array_length(
                    ARRAY(SELECT unnest(a.cat_ids) INTERSECT SELECT unnest(b.cat_ids)), 1
                ), 0)::numeric
                / NULLIF(COALESCE(array_length(
                    ARRAY(SELECT unnest(a.cat_ids) UNION SELECT unnest(b.cat_ids)), 1
                ), 0), 0) * par.category_weight AS cat_score,
                similarity(a.title, b.title)::numeric * par.title_weight AS title_score,
                b.pop_score
        SQL;
    }

    /** Self-pairs, exclusions, pins and self-excluded related products drop out; fully pinned products rank nothing. */
    private static function validPairPredicate(): string
    {
        return <<<'SQL'
        a.id != b.id AND b.rankable AND a.num_pins < par.max_results
              AND (a.excluded_external_ids IS NULL OR b.external_id != ALL(a.excluded_external_ids))
              AND b.id != ALL(a.pinned_product_ids)
        SQL;
    }
}
