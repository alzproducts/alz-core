<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Removes the stock Supabase SECURITY DEFINER functions PUBLIC can execute: drops the pgsodium
 * extension and revokes PUBLIC EXECUTE on public.rls_auto_enable(), keeping its event trigger
 * and its grants to the API roles.
 *
 * Re-runnable. Forward-only.
 */
return new class extends Migration {
    public function up(): void
    {
        // intentional: no CASCADE, so an object depending on pgsodium fails the migration instead of vanishing.
        DB::statement('DROP EXTENSION IF EXISTS pgsodium');

        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF to_regprocedure('public.rls_auto_enable()') IS NOT NULL THEN
                    REVOKE EXECUTE ON FUNCTION public.rls_auto_enable() FROM PUBLIC;
                END IF;
            END
            $$
        SQL);

        // intentional: a REVOKE by a non-owner only warns, so the result is checked rather than trusted.
        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'pgsodium') THEN
                    RAISE EXCEPTION 'pgsodium is still installed';
                END IF;

                IF EXISTS (
                    SELECT 1
                    FROM pg_proc p, aclexplode(coalesce(p.proacl, acldefault('f', p.proowner))) a
                    WHERE p.oid = to_regprocedure('public.rls_auto_enable()')
                      AND a.grantee = 0
                      AND a.privilege_type = 'EXECUTE'
                ) THEN
                    RAISE EXCEPTION 'PUBLIC can still execute public.rls_auto_enable()';
                END IF;
            END
            $$
        SQL);
    }

    public function down(): void
    {
        throw new LogicException('forward-only');
    }
};
