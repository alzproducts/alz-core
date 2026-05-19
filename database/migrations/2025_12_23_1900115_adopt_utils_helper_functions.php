<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adoption Migration: utils schema helper functions
 *
 * Creates utility functions for RLS policy checks that prevent infinite recursion.
 * These functions use SECURITY DEFINER to bypass RLS when checking user roles.
 *
 * Functions created:
 *   - utils.is_admin(uuid) - checks if user has admin role
 *   - utils.is_admin_or_manager(uuid) - checks if user has admin or manager role
 *
 * Dependencies:
 *   - utils schema (190001)
 *   - access.roles table (190011)
 *   - access.user_roles table (190011)
 *
 * Used by:
 *   - RLS policies on config.dashboard (190014)
 *
 * In production, these functions already exist - migration skips creation.
 * In CI/testing, creates the functions from scratch.
 *
 * Source: ${FRONTEND_APP}/supabase/migrations/
 *   - 20250814135342_fix_role_assignment_rls_infinite_recursion.sql
 */
return new class extends Migration {
    public function up(): void
    {
        $this->createIsAdmin();
        $this->createIsAdminOrManager();
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS utils.is_admin_or_manager(uuid)');
        DB::statement('DROP FUNCTION IF EXISTS utils.is_admin(uuid)');
    }

    private function createIsAdmin(): void
    {
        if ($this->functionExists('utils', 'is_admin')) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION utils.is_admin(check_user_id UUID)
            RETURNS BOOLEAN
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public, access
            AS $$
            BEGIN
              RETURN EXISTS (
                SELECT 1
                FROM access.user_roles ur
                JOIN access.roles r ON ur.role_id = r.id
                WHERE ur.user_id = check_user_id
                AND r.name = 'admin'
              );
            END;
            $$
        SQL);

        // Grant execute to authenticated users
        DB::statement('GRANT EXECUTE ON FUNCTION utils.is_admin(UUID) TO authenticated');
    }

    private function createIsAdminOrManager(): void
    {
        if ($this->functionExists('utils', 'is_admin_or_manager')) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION utils.is_admin_or_manager(check_user_id UUID)
            RETURNS BOOLEAN
            LANGUAGE plpgsql
            SECURITY DEFINER
            SET search_path = public, access
            AS $$
            BEGIN
              RETURN EXISTS (
                SELECT 1
                FROM access.user_roles ur
                JOIN access.roles r ON ur.role_id = r.id
                WHERE ur.user_id = check_user_id
                AND r.name IN ('admin', 'manager')
              );
            END;
            $$
        SQL);

        // Grant execute to authenticated users
        DB::statement('GRANT EXECUTE ON FUNCTION utils.is_admin_or_manager(UUID) TO authenticated');
    }

    private function functionExists(string $schema, string $functionName): bool
    {
        $result = DB::selectOne(
            'SELECT EXISTS (
                SELECT 1 FROM information_schema.routines
                WHERE routine_schema = ?
                AND routine_name = ?
            ) as exists',
            [$schema, $functionName],
        );

        return $result->exists;
    }
};
