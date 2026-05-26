<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drops `marketing.contact_submission_dashboard_view` and creates
 * `marketing.potential_conversions_view` — UNION ALL of form submissions and
 * ad-attributed phone calls. Form side preserves the LATERAL subqueries +
 * HelpScout join from the previous view; call side attributes via
 * tracking_number_shown match within the 6-hour window. Ambiguous calls
 * (>1 matching visit) are silently excluded via the window-function filter
 * `visit_match_count = 1`.
 *
 * @depends 2026_05_23_002842_rebuild_marketing_contact_submission_dashboard_view_multi_platform
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS marketing.contact_submission_dashboard_view');

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
            LEFT JOIN marketing.contact_submission_annotations annotations
                ON annotations.contact_submission_id = submissions.id
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
                NULL::boolean                               AS is_potential_quote,
                NULL::text                                  AS notes,
                NULL::timestamptz                           AS quoted_at,
                NULL::text                                  AS lead_status,
                NULL::text                                  AS quote_status,
                attributed.helpscout_conversation_id::text  AS helpscout_external_id,
                NULL::timestamptz                           AS dismissed_at,
                (attributed.gclid IS NOT NULL
                    OR attributed.msclkid IS NOT NULL
                    OR attributed.fbclid IS NOT NULL)       AS has_ad_id,
                attributed.caller_phone_number
            FROM (
                SELECT
                    calls.id                                       AS call_id,
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
            WHERE attributed.visit_match_count = 1
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS marketing.potential_conversions_view');

        DB::statement(<<<'SQL'
            CREATE VIEW marketing.contact_submission_dashboard_view AS
            SELECT
                submissions.id,
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
                    OR submissions.fbclid IS NOT NULL) AS has_ad_id
            FROM public_ingest.contact_submissions submissions
            LEFT JOIN marketing.contact_submission_annotations annotations
                ON annotations.contact_submission_id = submissions.id
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
        SQL);
    }
};
