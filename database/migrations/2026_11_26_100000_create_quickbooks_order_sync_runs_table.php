<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One row per QBO order sync run: what asked for it, its outcome counts and an open-job tally that ends it
 * (docs/architecture/quickbooks-order-sync.md). Only alz-core reads it, through its BYPASSRLS role.
 */
return new class extends Migration {
    /** @var list<string> */
    private const array SUPABASE_ROLES = ['anon', 'authenticated', 'service_role'];

    public function up(): void
    {
        // intentional: `order_references`, as `references` is a reserved word in the raw tally updates.
        DB::statement(<<<'SQL'
            CREATE TABLE quickbooks.order_sync_runs (
                id               uuid        PRIMARY KEY,
                trigger          text        NOT NULL CHECK (trigger IN ('range', 'manual', 'schedule')),
                force_retry      boolean     NOT NULL,
                range_from       date        NULL,
                range_to         date        NULL,
                order_references integer[]   NULL,
                status           text        NOT NULL DEFAULT 'queued'
                                             CHECK (status IN ('queued', 'running', 'completed', 'failed')),
                candidate_count  integer     NOT NULL,
                synced           integer     NOT NULL DEFAULT 0,
                already_synced   integer     NOT NULL DEFAULT 0,
                skipped          integer     NOT NULL DEFAULT 0,
                failed           integer     NOT NULL DEFAULT 0,
                not_attempted    integer     NOT NULL DEFAULT 0,
                open_jobs        integer     NOT NULL DEFAULT 0,
                job_failed       boolean     NOT NULL DEFAULT false,
                requested_at     timestamptz NOT NULL,
                started_at       timestamptz NULL,
                finished_at      timestamptz NULL
            )
        SQL);
        DB::statement('CREATE INDEX order_sync_runs_requested_at_index ON quickbooks.order_sync_runs (requested_at DESC, id DESC)');

        $this->revokeSupabaseAccess();
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS quickbooks.order_sync_runs');
    }

    /**
     * intentional: service_role bypasses RLS, so only the REVOKE keeps accounting state out of the Data API (COR-280 D22).
     * Skipping current_user keeps the migrating role's own access.
     */
    private function revokeSupabaseAccess(): void
    {
        $roles = "ARRAY['PUBLIC', '" . implode("', '", self::SUPABASE_ROLES) . "']";

        DB::statement(<<<SQL
            DO \$\$
            DECLARE
                grantee text;
                target  text;
            BEGIN
                FOREACH grantee IN ARRAY {$roles} LOOP
                    IF grantee = 'PUBLIC' THEN
                        target := 'PUBLIC';
                    ELSIF grantee <> current_user AND EXISTS (SELECT 1 FROM pg_roles WHERE rolname = grantee) THEN
                        target := quote_ident(grantee);
                    ELSE
                        CONTINUE;
                    END IF;

                    EXECUTE format('REVOKE ALL ON TABLE quickbooks.order_sync_runs FROM %s', target);
                END LOOP;
            END
            \$\$
        SQL);
    }
};
