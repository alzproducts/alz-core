<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adoption Migration: auth schema
 *
 * Supabase owns the `auth` schema (auth.uid(), auth.role(), auth.email(), auth.users).
 * Every environment alz-core migrates runs the Supabase baseline, so this only checks it is there.
 *
 * @see docs/adr/0016-migrate-on-the-supabase-baseline.md
 */
return new class extends Migration {
    public function up(): void
    {
        $uidFunctionExists = DB::selectOne(
            "SELECT EXISTS (
                SELECT 1 FROM pg_proc p
                JOIN pg_namespace n ON p.pronamespace = n.oid
                WHERE n.nspname = 'auth' AND p.proname = 'uid'
            ) as exists",
        );

        if (! $uidFunctionExists->exists) {
            throw new RuntimeException('auth.uid() is missing: migrate on the Supabase baseline (database/AGENTS.md)');
        }
    }

    public function down(): void
    {
        // intentional: Supabase owns the auth schema; nothing to undo.
    }
};
