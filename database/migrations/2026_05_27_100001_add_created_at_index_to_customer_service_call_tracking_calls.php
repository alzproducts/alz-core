<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The composite (tracking_number_dialled, created_at) index serves the view's
 * attribution JOIN, but the hourly collision sweep filters by created_at alone
 * — Postgres can't use the composite for that leading-column-missing query and
 * falls back to a seq scan as call volume grows.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement('CREATE INDEX idx_ct_calls_created ON customer_service.call_tracking_calls(created_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS customer_service.idx_ct_calls_created');
    }
};
