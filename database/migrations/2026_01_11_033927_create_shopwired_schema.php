<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the dedicated 'shopwired' PostgreSQL schema.
 *
 * Organizes all ShopWired-related tables (~30-40 expected) in a separate
 * namespace for clarity. Eloquent models reference tables as 'shopwired.orders'.
 */
return new class extends Migration {
    public function up(): void
    {
        // Check if schema already exists (idempotent)
        $schemaExists = DB::selectOne(
            "SELECT EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = 'shopwired') as exists",
        );

        if ($schemaExists->exists) {
            return;
        }

        DB::statement('CREATE SCHEMA IF NOT EXISTS shopwired');
    }

    public function down(): void
    {
        // Only drop if empty (no tables exist)
        $hasTables = DB::selectOne(
            "SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'shopwired') as exists",
        );

        if (! $hasTables->exists) {
            DB::statement('DROP SCHEMA IF EXISTS shopwired');
        }
    }
};
