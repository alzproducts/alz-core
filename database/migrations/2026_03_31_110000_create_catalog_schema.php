<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the dedicated 'catalog' PostgreSQL schema with Supabase permissions.
 *
 * Houses read-model views that span multiple source schemas (shopwired + linnworks).
 * Views are preferable to tables here because they project computed/joined data
 * for API read paths, keeping the source schemas clean.
 */
return new class extends Migration {
    public function up(): void
    {
        // Check if schema already exists (idempotent)
        $schemaExists = DB::selectOne(
            "SELECT EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = 'catalog') as exists",
        );

        if ($schemaExists !== null && $schemaExists->exists) {
            return;
        }

        DB::statement('CREATE SCHEMA IF NOT EXISTS catalog');

        // Grant schema usage to Supabase roles
        // service_role: Backend operations (bypasses RLS)
        // authenticated: Authenticated users (subject to RLS)
        DB::statement('GRANT USAGE ON SCHEMA catalog TO authenticated, service_role');

        // Set default privileges for tables/views created in this schema
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA catalog GRANT ALL ON TABLES TO service_role');
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA catalog GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO authenticated');
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA catalog GRANT USAGE, SELECT ON SEQUENCES TO service_role');
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA catalog GRANT USAGE, SELECT ON SEQUENCES TO authenticated');
    }

    public function down(): void
    {
        // Only drop if empty (no tables or views exist)
        $hasObjects = DB::selectOne(
            "SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'catalog') as exists",
        );

        if ($hasObjects === null || ! $hasObjects->exists) {
            $this->safeRevoke('ALTER DEFAULT PRIVILEGES IN SCHEMA catalog REVOKE ALL ON TABLES FROM service_role');
            $this->safeRevoke('ALTER DEFAULT PRIVILEGES IN SCHEMA catalog REVOKE ALL ON TABLES FROM authenticated');
            $this->safeRevoke('ALTER DEFAULT PRIVILEGES IN SCHEMA catalog REVOKE ALL ON SEQUENCES FROM service_role');
            $this->safeRevoke('ALTER DEFAULT PRIVILEGES IN SCHEMA catalog REVOKE ALL ON SEQUENCES FROM authenticated');
            $this->safeRevoke('REVOKE USAGE ON SCHEMA catalog FROM authenticated, service_role');

            DB::statement('DROP SCHEMA IF EXISTS catalog');
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
