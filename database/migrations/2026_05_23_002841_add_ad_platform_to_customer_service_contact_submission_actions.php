<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tags lead/quote action rows with the ad platform so a single submission can hold
 * one row per platform; the dashboard aggregates them per ADR-0002.
 *
 * Uniqueness uses two partial indexes — Postgres treats NULL as DISTINCT in a
 * regular UNIQUE index, which would let duplicate HelpScout (NULL) rows slip through.
 *
 * @depends 2026_02_01_200002_create_customer_service_contact_submission_actions_table
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('customer_service.contact_submission_actions', static function ($table): void {
            $table->string('ad_platform', 20)->nullable();
        });

        // Backfill: pre-multi-platform rows were Google-only.
        DB::statement(<<<'SQL'
            UPDATE customer_service.contact_submission_actions
            SET ad_platform = 'google'
            WHERE action_type IN ('lead_received', 'quote_issued')
        SQL);

        DB::statement('DROP INDEX IF EXISTS customer_service.idx_csa_unique_action');

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX idx_csa_unique_action_platform
                ON customer_service.contact_submission_actions(contact_submission_id, action_type, ad_platform)
                WHERE ad_platform IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX idx_csa_unique_action_no_platform
                ON customer_service.contact_submission_actions(contact_submission_id, action_type)
                WHERE ad_platform IS NULL
        SQL);

        // Preserves the `(contact_submission_id, action_type)` access path that the
        // partial indexes above can't serve — their WHERE predicates aren't inferable
        // from the dashboard view / `findActionStatus` queries.
        DB::statement(<<<'SQL'
            CREATE INDEX idx_csa_submission_action
                ON customer_service.contact_submission_actions(contact_submission_id, action_type)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE customer_service.contact_submission_actions
            ADD CONSTRAINT csa_ad_platform_check
            CHECK (ad_platform IS NULL OR ad_platform IN ('google', 'bing'))
        SQL);
    }

    public function down(): void
    {
        // Refuse rollback if Bing rows exist — silent loss would lose conversion-tracking history.
        $hasBingRows = DB::selectOne(<<<'SQL'
            SELECT EXISTS (
                SELECT 1 FROM customer_service.contact_submission_actions WHERE ad_platform = 'bing'
            ) AS exists
        SQL);

        if ($hasBingRows !== null && $hasBingRows->exists) {
            throw new RuntimeException(
                'Cannot roll back: Bing action rows exist in contact_submission_actions. '
                . 'Clean them up manually before rolling back this migration.',
            );
        }

        DB::statement('ALTER TABLE customer_service.contact_submission_actions DROP CONSTRAINT IF EXISTS csa_ad_platform_check');
        DB::statement('DROP INDEX IF EXISTS customer_service.idx_csa_unique_action_platform');
        DB::statement('DROP INDEX IF EXISTS customer_service.idx_csa_unique_action_no_platform');
        DB::statement('DROP INDEX IF EXISTS customer_service.idx_csa_submission_action');

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX idx_csa_unique_action
                ON customer_service.contact_submission_actions(contact_submission_id, action_type)
        SQL);

        Schema::table('customer_service.contact_submission_actions', static function ($table): void {
            $table->dropColumn('ad_platform');
        });
    }
};
