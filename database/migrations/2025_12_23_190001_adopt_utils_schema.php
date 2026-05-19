<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adoption Migration: utils schema (schema only)
 *
 * Creates the 'utils' schema container. Helper functions are created in a later migration
 * (adopt_utils_functions) after the tables they reference exist.
 *
 * In production, this schema already exists - migration skips creation.
 * In CI/testing, creates the schema from scratch.
 *
 * Source: ${FRONTEND_APP}/supabase/migrations/
 *   - 20250708051436_admin_manager_update_approval_policy.sql (schema creation)
 */
return new class extends Migration {
    public function up(): void
    {
        // Check if schema already exists (production case)
        $schemaExists = DB::selectOne(
            "SELECT EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = 'utils') as exists",
        );

        if ($schemaExists->exists) {
            // Schema exists in production - adoption complete
            return;
        }

        // CI/Testing: Create schema from scratch
        DB::statement('CREATE SCHEMA IF NOT EXISTS utils');
    }

    public function down(): void
    {
        // Note: Schema will be dropped by the functions migration's down() or CASCADE
        // Only drop if empty (no functions exist)
        $hasFunctions = DB::selectOne(
            "SELECT EXISTS (SELECT 1 FROM information_schema.routines WHERE routine_schema = 'utils') as exists",
        );

        if (! $hasFunctions->exists) {
            DB::statement('DROP SCHEMA IF EXISTS utils');
        }
    }
};
