<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adoption Migration: auth schema (mock for local development)
 *
 * Creates mock `auth` schema with functions and users table that mirror Supabase.
 * In production (Supabase), these already exist - migration skips.
 * In CI/testing (plain PostgreSQL), creates mocks that read from session variables.
 *
 * Functions created:
 *   - auth.uid() - Returns current user UUID from request.jwt.claims
 *   - auth.role() - Returns current role from request.jwt.claims
 *   - auth.email() - Returns current email from request.jwt.claims
 *
 * Table created:
 *   - auth.users - Minimal mock of Supabase auth.users for view/trigger support
 *
 * Usage in tests:
 *   SET LOCAL "request.jwt.claims" TO '{"sub": "uuid-here", "role": "authenticated"}';
 *   -- Now auth.uid() returns that UUID
 *
 * Why needed:
 *   - Triggers use auth.uid() for audit fields (added_by, updated_by)
 *   - The user_profiles view JOINs auth.users for email
 *   - The handle_new_user() trigger reads raw_user_meta_data
 *
 * @see https://github.com/orgs/supabase/discussions/4799
 */
return new class extends Migration {
    public function up(): void
    {
        // Check if auth schema with uid() function already exists (Supabase production)
        $uidFunctionExists = DB::selectOne(
            "SELECT EXISTS (
                SELECT 1 FROM pg_proc p
                JOIN pg_namespace n ON p.pronamespace = n.oid
                WHERE n.nspname = 'auth' AND p.proname = 'uid'
            ) as exists",
        );

        if ($uidFunctionExists->exists) {
            // Supabase provides auth.uid() - adoption complete
            return;
        }

        // CI/Testing: Create mock auth schema and functions
        DB::statement('CREATE SCHEMA IF NOT EXISTS auth');

        // Create auth.uid() - returns UUID from JWT claims
        // Handles both legacy (request.jwt.claim.sub) and new (request.jwt.claims JSON) formats
        // Uses NULLIF to handle empty string edge case (see GitHub issue #4244)
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION auth.uid()
            RETURNS uuid
            LANGUAGE sql
            STABLE
            AS $$
                SELECT NULLIF(
                    COALESCE(
                        current_setting('request.jwt.claim.sub', true),
                        (current_setting('request.jwt.claims', true)::jsonb ->> 'sub')
                    ),
                    ''
                )::uuid
            $$
        SQL);

        // Create auth.role() - returns role string from JWT claims
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION auth.role()
            RETURNS text
            LANGUAGE sql
            STABLE
            AS $$
                SELECT COALESCE(
                    current_setting('request.jwt.claim.role', true),
                    (current_setting('request.jwt.claims', true)::jsonb ->> 'role'),
                    'anon'
                )::text
            $$
        SQL);

        // Create auth.email() - returns email from JWT claims (used by some RLS policies)
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION auth.email()
            RETURNS text
            LANGUAGE sql
            STABLE
            AS $$
                SELECT COALESCE(
                    current_setting('request.jwt.claim.email', true),
                    (current_setting('request.jwt.claims', true)::jsonb ->> 'email')
                )::text
            $$
        SQL);

        // Create mock auth.users table for local development
        // Minimal columns needed by:
        //   - user_profiles view (id, email)
        //   - handle_new_user() trigger (id, raw_user_meta_data)
        //   - FK constraints from profiles, access.* tables (id)
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS auth.users (
                id uuid PRIMARY KEY,
                email text,
                raw_user_meta_data jsonb DEFAULT '{}'::jsonb,
                created_at timestamp with time zone DEFAULT now(),
                updated_at timestamp with time zone DEFAULT now()
            )
        SQL);

        // Grant access to Supabase roles (created in later migration)
        // Using IF EXISTS since roles may not exist yet during initial setup
        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'authenticated') THEN
                    GRANT SELECT ON auth.users TO authenticated;
                END IF;
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'service_role') THEN
                    GRANT ALL ON auth.users TO service_role;
                END IF;
            END
            $$
        SQL);
    }

    public function down(): void
    {
        // Only drop if we created it (not in Supabase environment)
        // Check for auth.refresh_tokens - a Supabase-specific table we don't mock
        // (We now create auth.users locally, so can't use that for detection)
        $isSupabase = DB::selectOne(
            "SELECT EXISTS (
                SELECT 1 FROM information_schema.tables
                WHERE table_schema = 'auth' AND table_name = 'refresh_tokens'
            ) as exists",
        );

        if ($isSupabase->exists) {
            // Supabase environment - don't drop auth schema
            return;
        }

        // Testing environment - safe to drop our mocks
        // Drop table first (has no dependents in test env since we skip FK constraints)
        DB::statement('DROP TABLE IF EXISTS auth.users CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS auth.email()');
        DB::statement('DROP FUNCTION IF EXISTS auth.role()');
        DB::statement('DROP FUNCTION IF EXISTS auth.uid()');
        DB::statement('DROP SCHEMA IF EXISTS auth');
    }
};
