<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the `dismissed_at` column to marketing.contact_submission_annotations.
 *
 * Stage 1 of COR-156: the column is added but never written. Triage / Awaiting-Quote
 * view filters apply `whereNull('dismissed_at')` against it — vacuously satisfied
 * until Stage 2 (COR-159) wires up the `/dismiss` endpoint that writes the column.
 *
 * Must run before the dashboard-view rebuild migration that selects this column.
 *
 * @depends 2026_05_17_200001_create_marketing_contact_submission_annotations
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE marketing.contact_submission_annotations ADD COLUMN dismissed_at TIMESTAMPTZ NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE marketing.contact_submission_annotations DROP COLUMN dismissed_at');
    }
};
