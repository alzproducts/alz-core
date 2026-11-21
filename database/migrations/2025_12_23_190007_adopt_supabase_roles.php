<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adoption Migration: Supabase PostgreSQL role grants
 *
 * Grants schema usage to the roles Supabase uses for RLS (anon, authenticated, service_role).
 * Supabase owns those roles; every environment alz-core migrates runs the Supabase baseline.
 *
 * Tests assume them within a session: DB::statement("SET ROLE authenticated"), combined with
 * SET LOCAL request.jwt.claims for auth.uid()/auth.role().
 *
 * @see docs/adr/0016-migrate-on-the-supabase-baseline.md
 */
return new class extends Migration {
    public function up(): void
    {
        $this->grantSchemaUsage();
    }

    public function down(): void
    {
        // intentional: Supabase owns these roles and their auth grants; nothing to undo.
    }

    private function grantSchemaUsage(): void
    {
        // Public schema - all roles get usage
        DB::statement('GRANT USAGE ON SCHEMA public TO anon, authenticated, service_role');

        // Access schema - only authenticated and service_role
        DB::statement('GRANT USAGE ON SCHEMA access TO authenticated, service_role');

        // Config schema - only authenticated and service_role
        DB::statement('GRANT USAGE ON SCHEMA config TO authenticated, service_role');

        // Utils schema - only authenticated and service_role
        DB::statement('GRANT USAGE ON SCHEMA utils TO authenticated, service_role');

        // Auth schema - needed for auth.uid(), auth.role() in RLS policies
        DB::statement('GRANT USAGE ON SCHEMA auth TO anon, authenticated, service_role');
        DB::statement('GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA auth TO anon, authenticated, service_role');
    }
};
