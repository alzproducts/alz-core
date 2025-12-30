<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adoption Migration: user_profiles view and get_user_profiles function
 *
 * Creates:
 *   - public.user_profiles VIEW - Aggregates profile, auth, role, and department data
 *   - access.get_user_profiles() FUNCTION - Security definer function for safe access
 *
 * Dependencies:
 *   - public.profiles table
 *   - auth.users table (real in Supabase, mock in local dev)
 *   - access.user_roles, access.roles tables
 *   - access.user_departments, access.departments tables
 */
return new class extends Migration {
    public function up(): void
    {
        if (! $this->viewExists('public', 'user_profiles')) {
            $this->createUserProfilesView();
        }

        if (! $this->functionExists('access', 'get_user_profiles')) {
            $this->createGetUserProfilesFunction();
        }
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS access.get_user_profiles(uuid)');
        DB::statement('DROP VIEW IF EXISTS public.user_profiles');
    }

    private function createUserProfilesView(): void
    {
        DB::statement(<<<'SQL'
            CREATE VIEW public.user_profiles AS
            SELECT p.id, p.first_name, p.last_name, p.avatar_url,
                   p.created_at, p.is_approved, p.last_sign_in, p.updated_at,
                   u.email, r.name AS role_name,
                   COALESCE(json_agg(json_build_object('id', d.id, 'name', d.name))
                       FILTER (WHERE d.id IS NOT NULL), '[]'::json) AS departments
            FROM profiles p
            JOIN auth.users u ON p.id = u.id
            LEFT JOIN access.user_roles ur ON p.id = ur.user_id
            LEFT JOIN access.roles r ON ur.role_id = r.id
            LEFT JOIN access.user_departments ud ON p.id = ud.user_id
            LEFT JOIN access.departments d ON ud.department_id = d.id
            GROUP BY p.id, p.first_name, p.last_name, p.avatar_url,
                     p.created_at, p.is_approved, p.last_sign_in, p.updated_at,
                     u.email, r.name
        SQL);
    }

    private function createGetUserProfilesFunction(): void
    {
        DB::statement(<<<'SQL'
            CREATE FUNCTION access.get_user_profiles(target_user_id uuid DEFAULT NULL)
            RETURNS SETOF user_profiles
            LANGUAGE plpgsql SECURITY DEFINER
            SET search_path TO 'public', 'access'
            AS $function$
            BEGIN
                IF auth.uid() IS NULL THEN
                    RAISE EXCEPTION 'Authentication required';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM profiles WHERE id = auth.uid() AND is_approved) THEN
                    RAISE EXCEPTION 'Only approved staff can access profiles';
                END IF;
                IF target_user_id IS NOT NULL THEN
                    RETURN QUERY SELECT * FROM user_profiles WHERE id = target_user_id;
                ELSE
                    RETURN QUERY SELECT * FROM user_profiles;
                END IF;
            END;
            $function$
        SQL);
    }

    private function viewExists(string $schema, string $name): bool
    {
        return DB::selectOne(
            'SELECT EXISTS (SELECT 1 FROM information_schema.views
             WHERE table_schema = ? AND table_name = ?) as exists',
            [$schema, $name],
        )->exists;
    }

    private function functionExists(string $schema, string $name): bool
    {
        return DB::selectOne(
            'SELECT EXISTS (SELECT 1 FROM pg_proc p JOIN pg_namespace n ON p.pronamespace = n.oid
             WHERE n.nspname = ? AND p.proname = ?) as exists',
            [$schema, $name],
        )->exists;
    }
};
