<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adoption Migration: access schema
 *
 * Creates the 'access' schema for role-based access control tables.
 * Contains: roles, permissions, departments, user_roles, user_departments,
 * role_permissions, department_permissions, user_permissions.
 *
 * In production, this schema already exists - migration skips creation.
 * In CI/testing, creates the schema from scratch.
 *
 * Source: ${FRONTEND_APP}/supabase/migrations/
 *   - 00000000000000_initial_schema.sql (line 348: create schema if not exists "access")
 */
return new class extends Migration {
    public function up(): void
    {
        $schemaExists = DB::selectOne(
            "SELECT EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = 'access') as exists",
        );

        if ($schemaExists->exists) {
            return;
        }

        DB::statement('CREATE SCHEMA IF NOT EXISTS access');
    }

    public function down(): void
    {
        // Only drop if no tables exist in the schema
        $hasTables = DB::selectOne(
            "SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'access') as exists",
        );

        if (! $hasTables->exists) {
            DB::statement('DROP SCHEMA IF EXISTS access');
        }
    }
};
