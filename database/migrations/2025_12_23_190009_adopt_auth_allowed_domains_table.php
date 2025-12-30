<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adoption Migration: public.auth_allowed_domains table
 *
 * Whitelist of email domains allowed to sign up.
 * Used by auth hooks to validate new user registrations.
 *
 * Example: domain = "alzproducts.co.uk" allows any @alzproducts.co.uk email.
 *
 * RLS: Enabled but NO policies - only service_role (BYPASSRLS) can access.
 * This is intentional for admin-only tables.
 *
 * Note: FK to profiles(id) for added_by column is included since profiles table exists.
 */
return new class extends Migration {
    public function up(): void
    {
        if ($this->tableExists('public', 'auth_allowed_domains')) {
            return;
        }

        $this->createTable();
        $this->createIndexes();
        $this->createConstraints();
        $this->grantPermissions();
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS public.auth_allowed_domains CASCADE');
    }

    private function createTable(): void
    {
        // Matches source exactly: column order preserved
        DB::statement(<<<'SQL'
            CREATE TABLE public.auth_allowed_domains (
                id uuid NOT NULL DEFAULT uuid_generate_v4(),
                domain text NOT NULL,
                added_by uuid,
                created_at timestamp with time zone DEFAULT now()
            )
        SQL);

        DB::statement('ALTER TABLE public.auth_allowed_domains ENABLE ROW LEVEL SECURITY');
    }

    private function createIndexes(): void
    {
        // Index on added_by for FK lookups
        DB::statement('CREATE INDEX auth_allowed_domains_added_by_idx ON public.auth_allowed_domains USING btree (added_by)');

        // Unique constraint on domain
        DB::statement('CREATE UNIQUE INDEX auth_allowed_domains_domain_key ON public.auth_allowed_domains USING btree (domain)');

        // Primary key
        DB::statement('CREATE UNIQUE INDEX auth_allowed_domains_pkey ON public.auth_allowed_domains USING btree (id)');
        DB::statement('ALTER TABLE public.auth_allowed_domains ADD CONSTRAINT auth_allowed_domains_pkey PRIMARY KEY USING INDEX auth_allowed_domains_pkey');
    }

    private function createConstraints(): void
    {
        // FK to profiles for added_by (NOT VALID + VALIDATE is PostgreSQL pattern for existing data)
        DB::statement(<<<'SQL'
            ALTER TABLE public.auth_allowed_domains
            ADD CONSTRAINT auth_allowed_domains_added_by_fkey
            FOREIGN KEY (added_by) REFERENCES profiles(id) NOT VALID
        SQL);
        DB::statement('ALTER TABLE public.auth_allowed_domains VALIDATE CONSTRAINT auth_allowed_domains_added_by_fkey');

        // Unique constraint using the index
        DB::statement('ALTER TABLE public.auth_allowed_domains ADD CONSTRAINT auth_allowed_domains_domain_key UNIQUE USING INDEX auth_allowed_domains_domain_key');
    }

    private function grantPermissions(): void
    {
        $roles = ['anon', 'authenticated', 'service_role'];
        $permissions = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER'];

        foreach ($roles as $role) {
            foreach ($permissions as $permission) {
                DB::statement("GRANT {$permission} ON TABLE public.auth_allowed_domains TO {$role}");
            }
        }
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
