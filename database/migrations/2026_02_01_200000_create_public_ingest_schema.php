<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the dedicated 'public_ingest' PostgreSQL schema with Supabase permissions.
 *
 * The public_ingest schema stores data submitted from public-facing forms.
 * This includes contact form submissions that are immutable snapshots.
 *
 * Sets up DEFAULT PRIVILEGES so tables created afterward automatically
 * inherit the correct permissions for Supabase roles.
 */
return new class extends Migration {
    public function up(): void
    {
        // Check if schema already exists (idempotent)
        $schemaExists = DB::selectOne(
            "SELECT EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = 'public_ingest') as exists",
        );

        if ($schemaExists !== null && $schemaExists->exists) {
            return;
        }

        DB::statement('CREATE SCHEMA IF NOT EXISTS public_ingest');

        // Grant schema usage to Supabase roles
        // service_role: Backend operations (bypasses RLS)
        // authenticated: Authenticated users (subject to RLS)
        DB::statement('GRANT USAGE ON SCHEMA public_ingest TO authenticated, service_role');

        // Set default privileges for tables created in this schema
        // This ensures new tables automatically get the right permissions
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public_ingest GRANT ALL ON TABLES TO service_role');
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public_ingest GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO authenticated');
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public_ingest GRANT USAGE, SELECT ON SEQUENCES TO service_role');
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public_ingest GRANT USAGE, SELECT ON SEQUENCES TO authenticated');
    }

    public function down(): void
    {
        // Only drop if empty (no tables exist)
        $hasTables = DB::selectOne(
            "SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'public_ingest') as exists",
        );

        if ($hasTables === null || ! $hasTables->exists) {
            // Revoke default privileges first
            $this->safeRevoke('ALTER DEFAULT PRIVILEGES IN SCHEMA public_ingest REVOKE ALL ON TABLES FROM service_role');
            $this->safeRevoke('ALTER DEFAULT PRIVILEGES IN SCHEMA public_ingest REVOKE ALL ON TABLES FROM authenticated');
            $this->safeRevoke('ALTER DEFAULT PRIVILEGES IN SCHEMA public_ingest REVOKE ALL ON SEQUENCES FROM service_role');
            $this->safeRevoke('ALTER DEFAULT PRIVILEGES IN SCHEMA public_ingest REVOKE ALL ON SEQUENCES FROM authenticated');

            // Revoke schema usage
            $this->safeRevoke('REVOKE USAGE ON SCHEMA public_ingest FROM authenticated, service_role');

            DB::statement('DROP SCHEMA IF EXISTS public_ingest');
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
