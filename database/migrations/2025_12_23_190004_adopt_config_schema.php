<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adoption Migration: config schema (schema only)
 *
 * Creates the 'config' schema container for application configuration.
 * Tables (like config.dashboard) are created in a later migration.
 *
 * In production, this schema already exists - migration skips creation.
 * In CI/testing, creates the schema from scratch.
 *
 * Source: /Users/tom/WebstormProjects/alz-admin/supabase/migrations/
 *   - 20250901103108_add_dashboard_config.sql (schema creation)
 */
return new class extends Migration {
    public function up(): void
    {
        // Check if schema already exists (production case)
        $schemaExists = DB::selectOne(
            "SELECT EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = 'config') as exists",
        );

        if ($schemaExists->exists) {
            // Schema exists in production - adoption complete
            return;
        }

        // CI/Testing: Create schema from scratch
        DB::statement('CREATE SCHEMA IF NOT EXISTS config');
    }

    public function down(): void
    {
        // Only drop if empty (no tables exist)
        $hasTables = DB::selectOne(
            "SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'config') as exists",
        );

        if (! $hasTables->exists) {
            DB::statement('DROP SCHEMA IF EXISTS config');
        }
    }
};
