<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enables Row Level Security on all public framework/infrastructure tables.
 *
 * No policies are added because:
 * - Laravel connects as `postgres` (BYPASSRLS) — RLS is invisible to it
 * - `service_role` also has BYPASSRLS
 * - `anon` and `authenticated` have no business accessing these tables
 * - RLS with no policies effectively blocks anon/authenticated roles
 *
 * This resolves Supabase dashboard RLS warnings for these framework tables.
 *
 * Tables covered:
 * - public.cache
 * - public.cache_locks
 * - public.jobs
 * - public.job_batches
 * - public.migrations
 * - public.failed_jobs
 * - public.telescope_entries
 * - public.telescope_entries_tags
 * - public.telescope_monitoring
 * - public.sync_cursors
 */
return new class extends Migration {
    /**
     * @var list<string>
     */
    private array $tables = [
        'public.cache',
        'public.cache_locks',
        'public.jobs',
        'public.job_batches',
        'public.migrations',
        'public.failed_jobs',
        'public.telescope_entries',
        'public.telescope_entries_tags',
        'public.telescope_monitoring',
        'public.sync_cursors',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if ($this->tableExists($table)) {
                DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if ($this->tableExists($table)) {
                DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
            }
        }
    }

    private function tableExists(string $qualifiedTable): bool
    {
        [$schema, $table] = explode('.', $qualifiedTable);

        return DB::selectOne(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ?',
            [$schema, $table],
        ) !== null;
    }
};
