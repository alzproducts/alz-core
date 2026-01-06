<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adoption Migration: public.profiles table
 *
 * The profiles table stores user profile data and links to auth.users via id (1:1).
 * Each Supabase auth user gets a corresponding profile row.
 *
 * RLS Policies:
 *   - SELECT: Authenticated users can view all profiles
 *   - INSERT: Users can only insert their own profile (id = auth.uid())
 *   - UPDATE: Users can only update their own profile (id = auth.uid())
 *
 * Note: FK constraint to auth.users is documented but not enforced in Laravel
 * because auth.users is managed by Supabase and doesn't exist in test environments.
 *
 * The handle_new_user() trigger (created later) auto-creates profiles on signup.
 */
return new class extends Migration {
    public function up(): void
    {
        if ($this->tableExists('public', 'profiles')) {
            return; // Skip if already exists (Supabase environment)
        }

        $this->createTable();
        $this->createIndexes();
        $this->grantPermissions();
        $this->createRlsPolicies();
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS public.profiles CASCADE');
    }

    private function createTable(): void
    {
        // Matches source exactly: column order preserved
        DB::statement(<<<'SQL'
            CREATE TABLE public.profiles (
                id uuid NOT NULL,
                updated_at timestamp with time zone,
                first_name text NOT NULL,
                last_name text,
                avatar_url text,
                last_sign_in timestamp with time zone,
                created_at timestamp with time zone DEFAULT now(),
                role user_role NOT NULL DEFAULT 'unauthorized'::user_role,
                is_approved boolean NOT NULL DEFAULT false
            )
        SQL);

        DB::statement('ALTER TABLE public.profiles ENABLE ROW LEVEL SECURITY');
    }

    private function createIndexes(): void
    {
        // Primary key via unique index (matches Supabase pattern)
        DB::statement('CREATE UNIQUE INDEX profiles_pkey ON public.profiles USING btree (id)');
        DB::statement('ALTER TABLE public.profiles ADD CONSTRAINT profiles_pkey PRIMARY KEY USING INDEX profiles_pkey');

        // Note: FK to auth.users intentionally omitted
        // In Supabase: profiles.id references auth.users(id) ON DELETE CASCADE
        // In Laravel tests: auth.users doesn't exist, so we skip this constraint
    }

    private function grantPermissions(): void
    {
        // Matches source: all three roles get full table permissions
        $roles = ['anon', 'authenticated', 'service_role'];
        $permissions = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER'];

        foreach ($roles as $role) {
            foreach ($permissions as $permission) {
                DB::statement("GRANT {$permission} ON TABLE public.profiles TO {$role}");
            }
        }
    }

    private function createRlsPolicies(): void
    {
        // Policy 1: Authenticated users can view all profiles
        // Syntax matches source exactly (nested SELECT with alias, triple parens)
        DB::statement(<<<'SQL'
            CREATE POLICY "Profiles are viewable by authenticated users."
            ON public.profiles
            AS PERMISSIVE
            FOR SELECT
            TO authenticated
            USING ((( SELECT auth.role() AS role) = 'authenticated'::text))
        SQL);

        // Policy 2: Users can insert their own profile
        DB::statement(<<<'SQL'
            CREATE POLICY "Users can insert their own profile."
            ON public.profiles
            AS PERMISSIVE
            FOR INSERT
            TO authenticated
            WITH CHECK ((( SELECT auth.uid() AS uid) = id))
        SQL);

        // Policy 3: Users can update their own profile
        DB::statement(<<<'SQL'
            CREATE POLICY "Users can update their own profiles"
            ON public.profiles
            AS PERMISSIVE
            FOR UPDATE
            TO authenticated
            USING ((( SELECT auth.uid() AS uid) = id))
        SQL);
    }

    private function tableExists(string $schema, string $table): bool
    {
        $result = DB::selectOne(<<<'SQL'
            SELECT EXISTS (
                SELECT 1 FROM information_schema.tables
                WHERE table_schema = ? AND table_name = ?
            ) as exists
        SQL, [$schema, $table]);

        return $result->exists;
    }
};
