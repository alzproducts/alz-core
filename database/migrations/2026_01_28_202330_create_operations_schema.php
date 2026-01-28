<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the dedicated 'operations' PostgreSQL schema with Supabase permissions.
 *
 * The operations schema is for cross-platform operational data that doesn't belong
 * to a single integration. Examples: SKU change audit logs, cross-system sync status.
 *
 * Sets up DEFAULT PRIVILEGES so tables created afterward automatically
 * inherit the correct permissions for Supabase roles.
 */
return new class extends Migration {
    public function up(): void
    {
        // Check if schema already exists (idempotent)
        $schemaExists = DB::selectOne(
            "SELECT EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = 'operations') as exists",
        );

        if ($schemaExists !== null && $schemaExists->exists) {
            return;
        }

        DB::statement('CREATE SCHEMA IF NOT EXISTS operations');

        // Grant schema usage to Supabase roles
        // service_role: Backend operations (bypasses RLS)
        // authenticated: Authenticated users (subject to RLS)
        DB::statement('GRANT USAGE ON SCHEMA operations TO authenticated, service_role');

        // Set default privileges for tables created in this schema
        // This ensures new tables automatically get the right permissions
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA operations GRANT ALL ON TABLES TO service_role');
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA operations GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO authenticated');
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA operations GRANT USAGE, SELECT ON SEQUENCES TO service_role');
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA operations GRANT USAGE, SELECT ON SEQUENCES TO authenticated');
    }

    public function down(): void
    {
        // Only drop if empty (no tables exist)
        $hasTables = DB::selectOne(
            "SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'operations') as exists",
        );

        if ($hasTables === null || ! $hasTables->exists) {
            // Revoke default privileges first
            $this->safeRevoke('ALTER DEFAULT PRIVILEGES IN SCHEMA operations REVOKE ALL ON TABLES FROM service_role');
            $this->safeRevoke('ALTER DEFAULT PRIVILEGES IN SCHEMA operations REVOKE ALL ON TABLES FROM authenticated');
            $this->safeRevoke('ALTER DEFAULT PRIVILEGES IN SCHEMA operations REVOKE ALL ON SEQUENCES FROM service_role');
            $this->safeRevoke('ALTER DEFAULT PRIVILEGES IN SCHEMA operations REVOKE ALL ON SEQUENCES FROM authenticated');

            // Revoke schema usage
            $this->safeRevoke('REVOKE USAGE ON SCHEMA operations FROM authenticated, service_role');

            DB::statement('DROP SCHEMA IF EXISTS operations');
        }
    }

    private function safeRevoke(string $sql): void
    {
        try {
            DB::statement($sql);
        } catch (Exception) {
            // @ignoreException Role/schema may not exist during rollback
        }
    }
};
