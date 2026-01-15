<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Grants Supabase role permissions on the shopwired schema.
 *
 * The shopwired schema was created in 2026_01_11_033927 but without
 * permission grants. Without USAGE on the schema, PostgreSQL treats
 * all objects as invisible (42P01: relation does not exist).
 *
 * This migration adds the same permission pattern used for other schemas
 * (access, config, utils) in adopt_supabase_roles.php.
 */
return new class extends Migration {
    public function up(): void
    {
        // Check if schema exists (may not exist in fresh CI environment)
        $schemaExists = DB::selectOne(
            "SELECT EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = 'shopwired') as exists",
        );

        if ($schemaExists === null || ! $schemaExists->exists) {
            return;
        }

        // Grant schema usage to Supabase roles
        // service_role: Backend operations (bypasses RLS)
        // authenticated: Authenticated users (subject to RLS)
        DB::statement('GRANT USAGE ON SCHEMA shopwired TO authenticated, service_role');

        // Grant table permissions for existing tables
        // service_role gets ALL (backend sync jobs)
        // authenticated gets standard CRUD (for potential future RLS use)
        DB::statement('GRANT ALL ON ALL TABLES IN SCHEMA shopwired TO service_role');
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA shopwired TO authenticated');

        // Grant sequence permissions (for auto-increment/serial columns)
        DB::statement('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA shopwired TO service_role');
        DB::statement('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA shopwired TO authenticated');

        // Set default privileges for future tables created in this schema
        // This ensures new tables automatically get the right permissions
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA shopwired GRANT ALL ON TABLES TO service_role');
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA shopwired GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO authenticated');
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA shopwired GRANT USAGE, SELECT ON SEQUENCES TO service_role');
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA shopwired GRANT USAGE, SELECT ON SEQUENCES TO authenticated');
    }

    public function down(): void
    {
        // Check if schema exists before revoking
        $schemaExists = DB::selectOne(
            "SELECT EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = 'shopwired') as exists",
        );

        if ($schemaExists === null || ! $schemaExists->exists) {
            return;
        }

        // Revoke default privileges first
        $this->safeRevoke('ALTER DEFAULT PRIVILEGES IN SCHEMA shopwired REVOKE ALL ON TABLES FROM service_role');
        $this->safeRevoke('ALTER DEFAULT PRIVILEGES IN SCHEMA shopwired REVOKE ALL ON TABLES FROM authenticated');
        $this->safeRevoke('ALTER DEFAULT PRIVILEGES IN SCHEMA shopwired REVOKE ALL ON SEQUENCES FROM service_role');
        $this->safeRevoke('ALTER DEFAULT PRIVILEGES IN SCHEMA shopwired REVOKE ALL ON SEQUENCES FROM authenticated');

        // Revoke table and sequence permissions
        $this->safeRevoke('REVOKE ALL ON ALL TABLES IN SCHEMA shopwired FROM service_role, authenticated');
        $this->safeRevoke('REVOKE ALL ON ALL SEQUENCES IN SCHEMA shopwired FROM service_role, authenticated');

        // Revoke schema usage
        $this->safeRevoke('REVOKE USAGE ON SCHEMA shopwired FROM authenticated, service_role');
    }

    private function safeRevoke(string $sql): void
    {
        try {
            DB::statement($sql);
        } catch (Exception) {
            // Ignore errors (role/schema may not exist during rollback)
        }
    }
};
