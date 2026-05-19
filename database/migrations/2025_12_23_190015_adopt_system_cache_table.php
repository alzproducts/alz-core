<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adopts the public.system_cache table for API session backup and critical data caching.
 *
 * Provides database-backed cache for critical system data (e.g., Linnworks API sessions)
 * that must persist during KV cache outages. RLS enabled but no policies - service-role only.
 *
 * Note: pg_cron cleanup job exists in Supabase but is not created locally (extension unavailable).
 * Local cleanup should be handled by Laravel scheduler if needed.
 *
 * @see ${FRONTEND_APP}/supabase/migrations/20250902051135_add_system_cache_table.sql
 */
return new class extends Migration {
    public function up(): void
    {
        // Create table only if not exists
        if (! $this->tableExists('public', 'system_cache')) {
            DB::statement(<<<'SQL'
                CREATE TABLE public.system_cache (
                    key TEXT PRIMARY KEY,
                    value JSONB NOT NULL,
                    expires_at TIMESTAMPTZ NOT NULL,
                    created_at TIMESTAMPTZ DEFAULT NOW(),
                    updated_at TIMESTAMPTZ DEFAULT NOW()
                )
            SQL);

            // Comments (only needed when creating table)
            DB::statement("COMMENT ON TABLE public.system_cache IS 'System-level cache for API sessions and other critical data with automatic expiration. Used for dual-store persistence pattern where KV cache is primary and this table serves as reliable backup.'");
            DB::statement("COMMENT ON COLUMN public.system_cache.key IS 'Unique identifier for the cached item (e.g., \"linnworks:session\")'");
            DB::statement("COMMENT ON COLUMN public.system_cache.value IS 'JSON data stored in the cache, structure varies by key type'");
            DB::statement("COMMENT ON COLUMN public.system_cache.expires_at IS 'Timestamp when this cache entry expires and should be deleted'");
            DB::statement("COMMENT ON COLUMN public.system_cache.created_at IS 'Timestamp when the cache entry was first created'");
            DB::statement("COMMENT ON COLUMN public.system_cache.updated_at IS 'Timestamp when the cache entry was last updated (managed by trigger)'");

            // Enable RLS - no policies needed (service-role only access)
            DB::statement('ALTER TABLE public.system_cache ENABLE ROW LEVEL SECURITY');
        }

        // Always check indexes
        $this->createIndexIfNotExists(
            'idx_system_cache_expires_at',
            'CREATE INDEX idx_system_cache_expires_at ON public.system_cache(expires_at)',
        );

        $this->createIndexIfNotExists(
            'idx_system_cache_key_expires_at',
            'CREATE INDEX idx_system_cache_key_expires_at ON public.system_cache(key, expires_at)',
        );

        // Always check trigger
        $this->createTriggerIfNotExists(
            'public.system_cache',
            'update_system_cache_timestamp',
            <<<'SQL'
                CREATE TRIGGER update_system_cache_timestamp
                BEFORE UPDATE ON public.system_cache
                FOR EACH ROW EXECUTE FUNCTION public.update_timestamp()
            SQL,
        );

        // Note: pg_cron cleanup job (cron.schedule) is NOT created here.
        // It requires the pg_cron extension which is Supabase-specific.
        // In Supabase production, the job already exists.
        // In local dev, use Laravel's scheduler for cleanup if needed.
    }

    public function down(): void
    {
        // Drop trigger
        DB::statement('DROP TRIGGER IF EXISTS update_system_cache_timestamp ON public.system_cache');

        // Drop table (CASCADE handles indexes)
        DB::statement('DROP TABLE IF EXISTS public.system_cache CASCADE');
    }

    private function tableExists(string $schema, string $table): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ?',
            [$schema, $table],
        ) !== null;
    }

    private function createIndexIfNotExists(string $indexName, string $sql): void
    {
        $exists = DB::selectOne(
            'SELECT 1 FROM pg_indexes WHERE indexname = ?',
            [$indexName],
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
