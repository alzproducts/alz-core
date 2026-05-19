<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adopts the config.dashboard table for dashboard configuration storage.
 *
 * Table stores JSONB settings per dashboard table (thresholds, tags, display options).
 * RLS policies allow authenticated users to read, admins/managers to modify.
 *
 * @see ${FRONTEND_APP}/supabase/migrations/20250901103108_add_dashboard_config.sql
 * @see ${FRONTEND_APP}/supabase/migrations/20250906154806_update_dashboard_config_policy_for_managers.sql
 */
return new class extends Migration {
    public function up(): void
    {
        // Create table only if not exists
        if (! $this->tableExists('config', 'dashboard')) {
            DB::statement(<<<'SQL'
                CREATE TABLE config.dashboard (
                    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
                    table_name text UNIQUE NOT NULL,
                    settings jsonb NOT NULL DEFAULT '{}',
                    enabled boolean DEFAULT true,
                    created_at timestamptz DEFAULT now(),
                    added_by uuid,
                    updated_at timestamptz DEFAULT now(),
                    updated_by uuid,
                    CONSTRAINT valid_table_name CHECK (table_name ~ '^[a-z_]+')
                )
            SQL);

            // Note: FK constraints to auth.users are omitted intentionally.
            // In production, Supabase manages auth.users. In local dev, we have a mock.
            // The constraint is documented but not enforced to avoid test complications.

            // Comments (only needed when creating table)
            DB::statement("COMMENT ON TABLE config.dashboard IS 'Stores configuration for dashboard tables (thresholds, tags, display options)'");
            DB::statement("COMMENT ON COLUMN config.dashboard.settings IS 'JSONB settings specific to each dashboard table, structure varies by table_name'");
            DB::statement("COMMENT ON COLUMN config.dashboard.table_name IS 'Identifier for the dashboard table this configuration applies to'");
            DB::statement("COMMENT ON COLUMN config.dashboard.enabled IS 'Whether this configuration is active'");

            // Grants (only needed when creating table)
            DB::statement('GRANT SELECT, INSERT, UPDATE ON config.dashboard TO authenticated');
            DB::statement('GRANT ALL ON config.dashboard TO service_role');

            // Enable RLS (only needed when creating table)
            DB::statement('ALTER TABLE config.dashboard ENABLE ROW LEVEL SECURITY');
        }

        // Always check indexes - even if table exists, indexes might be missing
        $this->createIndexIfNotExists(
            'config.dashboard',
            'idx_dashboard_table_name',
            'CREATE INDEX idx_dashboard_table_name ON config.dashboard(table_name)',
        );

        $this->createIndexIfNotExists(
            'config.dashboard',
            'idx_dashboard_enabled',
            'CREATE INDEX idx_dashboard_enabled ON config.dashboard(enabled)',
        );

        // Always check RLS policies - handles partial state
        $this->createPolicyIfNotExists(
            'config.dashboard',
            'Authenticated users can view dashboard configurations',
            <<<'SQL'
                CREATE POLICY "Authenticated users can view dashboard configurations"
                ON config.dashboard
                FOR SELECT
                TO authenticated
                USING (auth.role() = 'authenticated'::text)
            SQL,
        );

        $this->createPolicyIfNotExists(
            'config.dashboard',
            'Admins and managers can modify dashboard configurations',
            <<<'SQL'
                CREATE POLICY "Admins and managers can modify dashboard configurations"
                ON config.dashboard
                FOR ALL
                TO authenticated
                USING (utils.is_admin_or_manager(auth.uid()))
            SQL,
        );

        // Always check triggers - handles partial state
        $this->createTriggerIfNotExists(
            'config.dashboard',
            'set_dashboard_audit_insert',
            <<<'SQL'
                CREATE TRIGGER set_dashboard_audit_insert
                BEFORE INSERT ON config.dashboard
                FOR EACH ROW EXECUTE FUNCTION public.set_audit_fields_insert()
            SQL,
        );

        $this->createTriggerIfNotExists(
            'config.dashboard',
            'set_dashboard_audit_update',
            <<<'SQL'
                CREATE TRIGGER set_dashboard_audit_update
                BEFORE UPDATE ON config.dashboard
                FOR EACH ROW EXECUTE FUNCTION public.set_audit_fields_update()
            SQL,
        );
    }

    public function down(): void
    {
        // Drop triggers
        DB::statement('DROP TRIGGER IF EXISTS set_dashboard_audit_update ON config.dashboard');
        DB::statement('DROP TRIGGER IF EXISTS set_dashboard_audit_insert ON config.dashboard');

        // Drop policies
        DB::statement('DROP POLICY IF EXISTS "Admins and managers can modify dashboard configurations" ON config.dashboard');
        DB::statement('DROP POLICY IF EXISTS "Authenticated users can view dashboard configurations" ON config.dashboard');

        // Drop table (CASCADE handles indexes, constraints)
        DB::statement('DROP TABLE IF EXISTS config.dashboard CASCADE');
    }

    private function tableExists(string $schema, string $table): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ?',
            [$schema, $table],
        ) !== null;
    }

    private function createIndexIfNotExists(string $table, string $indexName, string $sql): void
    {
        $exists = DB::selectOne(
            'SELECT 1 FROM pg_indexes WHERE indexname = ?',
            [$indexName],
        );

        if ($exists === null) {
            DB::statement($sql);
        }
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
