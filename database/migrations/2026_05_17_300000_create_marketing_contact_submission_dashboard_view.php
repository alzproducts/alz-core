<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates marketing.contact_submission_dashboard_view.
 *
 * Read projection for the staff dashboard list endpoint. Joins:
 *  - public_ingest.contact_submissions          (1 row per submission)
 *  - marketing.contact_submission_annotations   (LEFT JOIN, 1:1 optional)
 *  - customer_service.contact_submission_actions (LEFT JOIN per action_type)
 *
 * The actions table has a UNIQUE INDEX on (contact_submission_id, action_type) so
 * each LEFT JOIN matches at most one row — no row multiplication, no latest-per-group
 * logic needed inside the view.
 *
 * is_potential_quote remains NULLABLE in the view: NULL means "no annotation row yet"
 * (i.e. untriaged), false means "explicitly not a potential quote", true means "is".
 * Filter semantics are a presentation concern and live in the query builder, not here.
 *
 * @depends 2026_05_17_200001_create_marketing_contact_submission_annotations
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

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS marketing.contact_submission_dashboard_view');
    }
};
