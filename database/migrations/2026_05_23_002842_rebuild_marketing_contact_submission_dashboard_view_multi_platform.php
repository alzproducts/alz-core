<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Replaces the lead/quote LEFT JOINs with LATERAL subqueries — multiple action rows
 * per submission (one per ad platform) would multiply dashboard rows on a naive join.
 * The LATERAL collapses them to one status per ADR-0002 priority. HelpScout actions
 * stay on a direct LEFT JOIN; they aren't platform-scoped.
 *
 * @depends 2026_05_23_002841_add_ad_platform_to_customer_service_contact_submission_actions
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS marketing.contact_submission_dashboard_view');

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

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS marketing.contact_submission_dashboard_view');

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
            LEFT JOIN customer_service.contact_submission_actions lead_action
                ON lead_action.contact_submission_id = submissions.id
               AND lead_action.action_type = 'lead_received'
            LEFT JOIN customer_service.contact_submission_actions quote_action
                ON quote_action.contact_submission_id = submissions.id
               AND quote_action.action_type = 'quote_issued'
            LEFT JOIN customer_service.contact_submission_actions helpscout_action
                ON helpscout_action.contact_submission_id = submissions.id
               AND helpscout_action.action_type = 'helpscout'
        SQL);
    }
};
