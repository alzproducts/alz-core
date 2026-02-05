<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the dedicated 'reviews_io' PostgreSQL schema with Supabase permissions.
 *
 * Organizes all Reviews.io-related tables (product_ratings, etc.) in a separate namespace
 * for clarity. Eloquent models reference tables as 'reviews_io.product_ratings'.
 *
 * Sets up DEFAULT PRIVILEGES so tables created afterward automatically
 * inherit the correct permissions for Supabase roles.
 */
return new class extends Migration {
    public function up(): void
    {
        // Check if schema already exists (idempotent)
        $schemaExists = DB::selectOne(
            "SELECT EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = 'reviews_io') as exists",
        );

        if ($schemaExists !== null && $schemaExists->exists) {
            return;
        }

        DB::statement('CREATE SCHEMA IF NOT EXISTS reviews_io');

        // Grant schema usage to Supabase roles
        // service_role: Backend operations (bypasses RLS)
        // authenticated: Authenticated users (subject to RLS)
        DB::statement('GRANT USAGE ON SCHEMA reviews_io TO authenticated, service_role');

        // Set default privileges for tables created in this schema
        // This ensures new tables automatically get the right permissions
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA reviews_io GRANT ALL ON TABLES TO service_role');
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA reviews_io GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO authenticated');
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA reviews_io GRANT USAGE, SELECT ON SEQUENCES TO service_role');
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA reviews_io GRANT USAGE, SELECT ON SEQUENCES TO authenticated');
    }

    public function down(): void
    {
        // Only drop if empty (no tables exist)
        $hasTables = DB::selectOne(
            "SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'reviews_io') as exists",
        );

        if ($hasTables === null || ! $hasTables->exists) {
            // Revoke default privileges first
            $this->safeRevoke('ALTER DEFAULT PRIVILEGES IN SCHEMA reviews_io REVOKE ALL ON TABLES FROM service_role');
            $this->safeRevoke('ALTER DEFAULT PRIVILEGES IN SCHEMA reviews_io REVOKE ALL ON TABLES FROM authenticated');
            $this->safeRevoke('ALTER DEFAULT PRIVILEGES IN SCHEMA reviews_io REVOKE ALL ON SEQUENCES FROM service_role');
            $this->safeRevoke('ALTER DEFAULT PRIVILEGES IN SCHEMA reviews_io REVOKE ALL ON SEQUENCES FROM authenticated');

            // Revoke schema usage
            $this->safeRevoke('REVOKE USAGE ON SCHEMA reviews_io FROM authenticated, service_role');

            DB::statement('DROP SCHEMA IF EXISTS reviews_io');
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
