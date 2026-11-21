<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One QBO connection per QBO environment. Only alz-core reads it, through its BYPASSRLS role.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE public.quickbooks_connections (
                id                            uuid        PRIMARY KEY DEFAULT gen_random_uuid(),
                environment                   text        NOT NULL UNIQUE CHECK (environment IN ('sandbox', 'production')),
                encrypted_refresh_token       text        NOT NULL,
                refresh_token_expires_at      timestamptz NOT NULL,
                refresh_token_hard_expires_at timestamptz NULL,
                created_at                    timestamptz NULL,
                updated_at                    timestamptz NULL
            )
        SQL);

        DB::statement('ALTER TABLE public.quickbooks_connections ENABLE ROW LEVEL SECURITY');

        // intentional: service_role bypasses RLS, so only the REVOKE keeps the token out of the Data API (COR-280 D22).
        // Skipping current_user keeps the migrating role's own access.
        DB::statement(<<<'SQL'
            DO $$
            DECLARE
                grantee text;
            BEGIN
                REVOKE ALL ON public.quickbooks_connections FROM PUBLIC;

                FOREACH grantee IN ARRAY ARRAY['anon', 'authenticated', 'service_role'] LOOP
                    IF grantee <> current_user AND EXISTS (SELECT 1 FROM pg_roles WHERE rolname = grantee) THEN
                        EXECUTE format('REVOKE ALL ON public.quickbooks_connections FROM %I', grantee);
                    END IF;
                END LOOP;
            END
            $$
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS public.quickbooks_connections');
    }
};
