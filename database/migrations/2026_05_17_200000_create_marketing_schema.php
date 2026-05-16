<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the dedicated 'marketing' PostgreSQL schema with Supabase permissions.
 *
 * The marketing schema stores lead/quote tracking and conversion data.
 *
 * Sets up DEFAULT PRIVILEGES so tables created afterward automatically
 * inherit the correct permissions for Supabase roles.
 */
return new class extends Migration {
    public function up(): void
    {
        $schemaExists = DB::selectOne(
            "SELECT EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = 'marketing') as exists",
        );

        if ($schemaExists !== null && $schemaExists->exists) {
            return;
        }

        DB::statement('CREATE SCHEMA IF NOT EXISTS marketing');

        DB::statement('GRANT USAGE ON SCHEMA marketing TO authenticated, service_role');

        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA marketing GRANT ALL ON TABLES TO service_role');
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA marketing GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO authenticated');
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA marketing GRANT USAGE, SELECT ON SEQUENCES TO service_role');
        DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA marketing GRANT USAGE, SELECT ON SEQUENCES TO authenticated');
    }

    public function down(): void
    {
        $hasTables = DB::selectOne(
            "SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'marketing') as exists",
        );

        if ($hasTables === null || ! $hasTables->exists) {
            $this->safeRevoke('ALTER DEFAULT PRIVILEGES IN SCHEMA marketing REVOKE ALL ON TABLES FROM service_role');
            $this->safeRevoke('ALTER DEFAULT PRIVILEGES IN SCHEMA marketing REVOKE ALL ON TABLES FROM authenticated');
            $this->safeRevoke('ALTER DEFAULT PRIVILEGES IN SCHEMA marketing REVOKE ALL ON SEQUENCES FROM service_role');
            $this->safeRevoke('ALTER DEFAULT PRIVILEGES IN SCHEMA marketing REVOKE ALL ON SEQUENCES FROM authenticated');

            $this->safeRevoke('REVOKE USAGE ON SCHEMA marketing FROM authenticated, service_role');

            DB::statement('DROP SCHEMA IF EXISTS marketing');
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
