<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repoints `marketing.potential_conversions_view` at the new
 * `marketing.potential_conversion_annotations` table on both UNION branches, surfaces
 * `lead_status` for call rows via a LATERAL aggregation over `call_tracking_actions`,
 * then drops the now-orphaned `marketing.contact_submission_annotations` table.
 *
 * Runs after the create+migrate step (the view still references the old table, so it
 * cannot be dropped until the view is rebuilt). Full DROP+CREATE rather than
 * CREATE OR REPLACE because the call branch's column sources change.
 *
 * @depends 2026_05_29_100000_create_marketing_potential_conversion_annotations_table
 */
return new class extends Migration {
    public function up(): void
    {
        // 1. Drop the view so the old annotation table it references can be dropped below
        DB::statement('DROP VIEW IF EXISTS marketing.potential_conversions_view');

        // 2. Recreate the view against the new table; surface lead_status for call rows
        DB::statement(<<<'SQL'
            CREATE VIEW marketing.potential_conversions_view AS
            SELECT
                submissions.id,
                'form'::text                 AS source,
                submissions.name,
                submissions.email,
                submissions.reason,
                submissions.customer_type,
                submissions.order_number,
                submissions.quantity,
                submissions.product,
                submissions.shopwired_customer_id,
                submissions.gclid,
                submissions.msclkid,
                submissions.fbclid,
                submissions.utm_source,
                submissions.utm_medium,
                submissions.utm_campaign,
                submissions.page_url,
                submissions.created_at,
                annotations.is_potential_quote,
                annotations.notes,
                annotations.quoted_at,
                lead_action.status           AS lead_status,
                quote_action.status          AS quote_status,
                helpscout_action.external_id AS helpscout_external_id,
                annotations.dismissed_at,
                (submissions.gclid IS NOT NULL
                    OR submissions.msclkid IS NOT NULL
                    OR submissions.fbclid IS NOT NULL) AS has_ad_id,
                NULL::text                   AS caller_phone_number
            FROM public_ingest.contact_submissions submissions
            LEFT JOIN marketing.potential_conversion_annotations annotations
                ON annotations.source_id = submissions.id
            LEFT JOIN LATERAL (
                SELECT
                    CASE
                        WHEN bool_or(a.status = 'completed') THEN 'completed'
                        WHEN bool_or(a.status = 'failed') THEN 'failed'
                        WHEN bool_or(a.status = 'processing') THEN 'processing'
                        WHEN bool_or(a.status = 'pending') THEN 'pending'
                    END AS status
                FROM customer_service.contact_submission_actions a
                WHERE a.contact_submission_id = submissions.id
                  AND a.action_type = 'lead_received'
            ) lead_action ON TRUE
            LEFT JOIN LATERAL (
                SELECT
                    CASE
                        WHEN bool_or(a.status = 'completed') THEN 'completed'
                        WHEN bool_or(a.status = 'failed') THEN 'failed'
                        WHEN bool_or(a.status = 'processing') THEN 'processing'
                        WHEN bool_or(a.status = 'pending') THEN 'pending'
                    END AS status
                FROM customer_service.contact_submission_actions a
                WHERE a.contact_submission_id = submissions.id
                  AND a.action_type = 'quote_issued'
            ) quote_action ON TRUE
            LEFT JOIN customer_service.contact_submission_actions helpscout_action
                ON helpscout_action.contact_submission_id = submissions.id
               AND helpscout_action.action_type = 'helpscout'

            UNION ALL

            SELECT
                attributed.call_id                          AS id,
                'call'::text                                AS source,
                NULL::text                                  AS name,
                NULL::text                                  AS email,
                NULL::text                                  AS reason,
                NULL::text                                  AS customer_type,
                NULL::text                                  AS order_number,
                NULL::integer                               AS quantity,
                NULL::jsonb                                 AS product,
                NULL::text                                  AS shopwired_customer_id,
                attributed.gclid,
                attributed.msclkid,
                attributed.fbclid,
                attributed.utm_source,
                attributed.utm_medium,
                attributed.utm_campaign,
                NULL::text                                  AS page_url,
                attributed.call_created_at                  AS created_at,
                annotations.is_potential_quote,
                annotations.notes,
                annotations.quoted_at,
                lead_action.status                          AS lead_status,
                NULL::text                                  AS quote_status,
                attributed.helpscout_conversation_id::text  AS helpscout_external_id,
                annotations.dismissed_at,
                (attributed.gclid IS NOT NULL
                    OR attributed.msclkid IS NOT NULL
                    OR attributed.fbclid IS NOT NULL)       AS has_ad_id,
                attributed.caller_phone_number
            FROM (
                SELECT
                    calls.id                                       AS call_id,
                    visits.id                                      AS visit_id,
                    visits.gclid,
                    visits.msclkid,
                    visits.fbclid,
                    visits.utm_source,
                    visits.utm_medium,
                    visits.utm_campaign,
                    calls.caller_phone_number,
                    calls.helpscout_conversation_id,
                    calls.created_at                               AS call_created_at,
                    COUNT(*) OVER (PARTITION BY calls.id)          AS visit_match_count
                FROM customer_service.call_tracking_calls calls
                INNER JOIN customer_service.call_tracking_visits visits
                    ON visits.tracking_number_shown = calls.tracking_number_dialled
                   AND calls.created_at >= visits.created_at
                   AND calls.created_at <  visits.created_at + INTERVAL '6 hours'
            ) attributed
            LEFT JOIN marketing.potential_conversion_annotations annotations
                ON annotations.source_id = attributed.call_id
            LEFT JOIN LATERAL (
                SELECT
                    CASE
                        WHEN bool_or(cta.status = 'completed') THEN 'completed'
                        WHEN bool_or(cta.status = 'failed') THEN 'failed'
                        WHEN bool_or(cta.status = 'processing') THEN 'processing'
                        WHEN bool_or(cta.status = 'pending') THEN 'pending'
                    END AS status
                FROM customer_service.call_tracking_actions cta
                WHERE cta.call_tracking_visit_id = attributed.visit_id
            ) lead_action ON TRUE
            WHERE attributed.visit_match_count = 1
        SQL);

        // 3. Drop the now-orphaned FK-constrained annotation table
        DB::statement('DROP TABLE IF EXISTS marketing.contact_submission_annotations');
    }

    public function down(): void
    {
        // Forward-only: this migration drops `contact_submission_annotations` and rewrites the
        // view's call branch. Reversing would silently lose call-row annotations (no FK target
        // to restore them to) and resurrect a table the app no longer writes. Local resets run
        // `make db-reset-full` forward and never invoke down().
        throw new RuntimeException(
            'Migration 2026_05_29_100001 is irreversible: the annotation-table drop and view rewrite cannot be faithfully undone.',
        );
    }
};
