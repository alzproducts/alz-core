<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adopts the public.user_api_keys table for encrypted third-party API keys.
 *
 * Stores encrypted API keys (AES-256-GCM) for services like ClickUp, HelpScout.
 * RLS policy allows users to manage only their own keys.
 *
 * @see /Users/tom/WebstormProjects/alz-admin/supabase/migrations/20250819175239_user_api_keys.sql
 */
return new class extends Migration {
    public function up(): void
    {
        // Create table only if not exists
        if (! $this->tableExists('public', 'user_api_keys')) {
            DB::statement(<<<'SQL'
                CREATE TABLE public.user_api_keys (
                    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                    user_id UUID NOT NULL,
                    service TEXT NOT NULL CHECK (service IN ('clickup', 'helpscout')),
                    encrypted_key TEXT NOT NULL CHECK (LENGTH(encrypted_key) > 0),
                    added_by UUID,
                    updated_by UUID,
                    created_at TIMESTAMPTZ DEFAULT NOW(),
                    updated_at TIMESTAMPTZ DEFAULT NOW(),
                    last_used_at TIMESTAMPTZ,
                    expires_at TIMESTAMPTZ,
                    is_valid BOOLEAN DEFAULT true,
                    UNIQUE(user_id, service)
                )
            SQL);

            // Note: FK constraints to auth.users are omitted intentionally.
            // In production, Supabase manages auth.users. In local dev, we have a mock.

            // Comments (only needed when creating table)
            DB::statement("COMMENT ON TABLE public.user_api_keys IS 'Stores encrypted API keys for third-party service integrations'");
            DB::statement("COMMENT ON COLUMN public.user_api_keys.service IS 'Service identifier (clickup, helpscout)'");
            DB::statement("COMMENT ON COLUMN public.user_api_keys.encrypted_key IS 'AES-256-GCM encrypted API key'");
            DB::statement("COMMENT ON COLUMN public.user_api_keys.last_used_at IS 'Timestamp of last API key usage for security monitoring'");
            DB::statement("COMMENT ON COLUMN public.user_api_keys.expires_at IS 'Optional expiration for key rotation policies'");
            DB::statement("COMMENT ON COLUMN public.user_api_keys.is_valid IS 'Quick invalidation flag without deletion'");

            // Enable RLS
            DB::statement('ALTER TABLE public.user_api_keys ENABLE ROW LEVEL SECURITY');
        }

        // Always check RLS policy
        $this->createPolicyIfNotExists(
            'public.user_api_keys',
            'Users manage own keys',
            <<<'SQL'
                CREATE POLICY "Users manage own keys"
                ON public.user_api_keys
                FOR ALL
                TO authenticated
                USING (auth.uid() = user_id)
            SQL,
        );

        // Always check triggers
        $this->createTriggerIfNotExists(
            'public.user_api_keys',
            'set_user_api_keys_audit_insert',
            <<<'SQL'
                CREATE TRIGGER set_user_api_keys_audit_insert
                BEFORE INSERT ON public.user_api_keys
                FOR EACH ROW EXECUTE FUNCTION public.set_audit_fields_insert()
            SQL,
        );

        $this->createTriggerIfNotExists(
            'public.user_api_keys',
            'set_user_api_keys_audit_update',
            <<<'SQL'
                CREATE TRIGGER set_user_api_keys_audit_update
                BEFORE UPDATE ON public.user_api_keys
                FOR EACH ROW EXECUTE FUNCTION public.set_audit_fields_update()
            SQL,
        );
    }

    public function down(): void
    {
        // Drop triggers
        DB::statement('DROP TRIGGER IF EXISTS set_user_api_keys_audit_update ON public.user_api_keys');
        DB::statement('DROP TRIGGER IF EXISTS set_user_api_keys_audit_insert ON public.user_api_keys');

        // Drop policy
        DB::statement('DROP POLICY IF EXISTS "Users manage own keys" ON public.user_api_keys');

        // Drop table (CASCADE handles indexes, constraints)
        DB::statement('DROP TABLE IF EXISTS public.user_api_keys CASCADE');
    }

    private function tableExists(string $schema, string $table): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ?',
            [$schema, $table],
        ) !== null;
    }

    private function createPolicyIfNotExists(string $table, string $policyName, string $sql): void
    {
        [$schema, $tableName] = explode('.', $table);

        $exists = DB::selectOne(
            'SELECT 1 FROM pg_policies WHERE schemaname = ? AND tablename = ? AND policyname = ?',
            [$schema, $tableName, $policyName],
        );

        if ($exists === null) {
            DB::statement($sql);
        }
    }

    private function createTriggerIfNotExists(string $table, string $triggerName, string $sql): void
    {
        [$schema, $tableName] = explode('.', $table);

        $exists = DB::selectOne(
            'SELECT 1 FROM information_schema.triggers WHERE event_object_schema = ? AND event_object_table = ? AND trigger_name = ?',
            [$schema, $tableName, $triggerName],
        );

        if ($exists === null) {
            DB::statement($sql);
        }
    }
};
