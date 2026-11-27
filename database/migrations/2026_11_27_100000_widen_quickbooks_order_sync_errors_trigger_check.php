<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lets the hourly catch-up run record its error rows with trigger `schedule`. The original check was declared inline
 * without a name, so PostgreSQL named it `order_sync_errors_trigger_check`; the replacement keeps that name.
 *
 * @depends 2026_11_21_200000_create_quickbooks_order_sync_schema
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE quickbooks.order_sync_errors DROP CONSTRAINT order_sync_errors_trigger_check');
        DB::statement(<<<'SQL'
            ALTER TABLE quickbooks.order_sync_errors
            ADD CONSTRAINT order_sync_errors_trigger_check
            CHECK (trigger IN ('range', 'manual', 'schedule'))
        SQL);
    }

    public function down(): void
    {
        // Refuse rollback while schedule rows exist: PostgreSQL refuses to add a check that existing rows violate.
        $hasScheduleRows = DB::selectOne(<<<'SQL'
            SELECT EXISTS (SELECT 1 FROM quickbooks.order_sync_errors WHERE trigger = 'schedule') AS exists
        SQL);

        if ($hasScheduleRows !== null && $hasScheduleRows->exists) {
            throw new RuntimeException(
                'Cannot roll back: schedule error rows exist in quickbooks.order_sync_errors. '
                . 'Clean them up manually before rolling back this migration.',
            );
        }

        DB::statement('ALTER TABLE quickbooks.order_sync_errors DROP CONSTRAINT order_sync_errors_trigger_check');
        DB::statement(<<<'SQL'
            ALTER TABLE quickbooks.order_sync_errors
            ADD CONSTRAINT order_sync_errors_trigger_check
            CHECK (trigger IN ('range', 'manual'))
        SQL);
    }
};
