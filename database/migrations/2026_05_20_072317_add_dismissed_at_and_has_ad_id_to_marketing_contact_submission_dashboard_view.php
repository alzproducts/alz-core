<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extends marketing.contact_submission_dashboard_view with `dismissed_at` + `has_ad_id`.
 *
 * - `dismissed_at` is the raw column from the annotations LEFT JOIN (added by the
 *   preceding migration). NULL until Stage 2's `/dismiss` endpoint ships.
 * - `has_ad_id` is a computed boolean: true iff at least one of gclid / msclkid / fbclid
 *   is set. Drives the "only paid-ad-driven leads in any view" filter without three OR
 *   clauses in every query.
 *
 * Up uses CREATE OR REPLACE VIEW (additive — append columns at the end). Down must
 * DROP then CREATE the previous body because Postgres can't shrink view columns via
 * OR REPLACE — same convention as `add_popularity_to_catalog_products_view`.
 *
 * @depends 2026_05_20_072316_add_dismissed_at_to_marketing_contact_submission_annotations
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW marketing.contact_submission_dashboard_view AS
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
                helpscout_action.external_id AS helpscout_external_id
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
