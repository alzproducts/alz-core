<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adoption Migration: Supabase PostgreSQL roles
 *
 * Creates the PostgreSQL roles that Supabase uses for RLS:
 *   - anon: Unauthenticated requests
 *   - authenticated: Authenticated users
 *   - service_role: Server-side operations (bypasses RLS)
 *
 * These roles are NOLOGIN - you don't connect as them directly.
 * Instead, use SET ROLE to assume them within a session:
 *
 *   DB::statement("SET ROLE authenticated");
 *
 * Combined with SET LOCAL request.jwt.claims for auth.uid()/auth.role(),
 * this enables RLS to work exactly like Supabase.
 *
 * In production (Supabase), these roles already exist.
 * In CI/testing, creates the roles from scratch.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->createRoles();
        $this->grantSchemaUsage();
    }

    public function down(): void
    {
        // Revoke permissions first (safe even if already revoked)
        $this->safeRevoke('REVOKE ALL ON SCHEMA public FROM anon, authenticated, service_role');
        $this->safeRevoke('REVOKE ALL ON SCHEMA access FROM authenticated, service_role');
        $this->safeRevoke('REVOKE ALL ON SCHEMA config FROM authenticated, service_role');
        $this->safeRevoke('REVOKE ALL ON SCHEMA utils FROM authenticated, service_role');
        $this->safeRevoke('REVOKE ALL ON SCHEMA auth FROM anon, authenticated, service_role');

        // Drop roles (will fail if they own objects)
        DB::statement('DROP ROLE IF EXISTS service_role');
        DB::statement('DROP ROLE IF EXISTS authenticated');
        DB::statement('DROP ROLE IF EXISTS anon');
    }

    private function createRoles(): void
    {
        if (! $this->roleExists('anon')) {
            DB::statement('CREATE ROLE anon NOLOGIN');
        }

        if (! $this->roleExists('authenticated')) {
            DB::statement('CREATE ROLE authenticated NOLOGIN');
        }

        if (! $this->roleExists('service_role')) {
            DB::statement('CREATE ROLE service_role NOLOGIN BYPASSRLS');
        }
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

    private function roleExists(string $roleName): bool
    {
        $result = DB::selectOne(
            'SELECT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = ?) as exists',
            [$roleName],
        );

        return $result->exists;
    }

    private function safeRevoke(string $sql): void
    {
        try {
            DB::statement($sql);
        } catch (Exception) {
            // Ignore errors (schema may not exist during rollback)
        }
    }
};
