<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates `marketing.potential_conversion_annotations` and migrates existing rows from
 * `marketing.contact_submission_annotations`.
 *
 * Source-agnostic replacement for the FK-constrained annotation table: keyed by a bare
 * `source_id` UUID (no FK, no source discriminator) so it can annotate both form
 * submissions (`contact_submissions.id`) and call rows (`call_tracking_calls.id`) —
 * UUIDs are globally unique across both sources. The old table is left in place; the
 * follow-up migration repoints the dashboard view and drops it (view dependency order).
 *
 * @depends 2026_05_27_100000_create_marketing_potential_conversions_view
 */
return new class extends Migration {
    public function up(): void
    {
        // 1. Create the source-agnostic annotation table (UUID PK, no FK — source_id holds either source's UUID)
        Schema::create('marketing.potential_conversion_annotations', static function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            $table->uuid('source_id');

            $table->boolean('is_potential_quote')->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('quoted_at')->nullable();
            $table->timestampTz('dismissed_at')->nullable();

            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        // 2. Enforce one annotation per source row (form submission or call)
        DB::statement('CREATE UNIQUE INDEX idx_pca_source_id ON marketing.potential_conversion_annotations(source_id)');

        // 3. Backfill existing form-row annotations (contact_submission_id becomes source_id)
        DB::statement(<<<'SQL'
            INSERT INTO marketing.potential_conversion_annotations
                (id, source_id, is_potential_quote, notes, quoted_at, dismissed_at, created_at, updated_at)
            SELECT id, contact_submission_id, is_potential_quote, notes, quoted_at, dismissed_at, created_at, updated_at
            FROM marketing.contact_submission_annotations
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing.potential_conversion_annotations');
    }
};
