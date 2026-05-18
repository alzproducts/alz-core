<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the marketing.contact_submission_annotations table.
 *
 * A 1:1 annotation layer on top of the immutable public_ingest.contact_submissions table.
 * Stores lead qualification and quoting state for marketing/conversion tracking.
 *
 * @depends 2026_05_17_200000_create_marketing_schema
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('marketing.contact_submission_annotations', static function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            $table->uuid('contact_submission_id');
            $table->foreign('contact_submission_id')
                ->references('id')
                ->on('public_ingest.contact_submissions')
                ->cascadeOnDelete();

            $table->boolean('is_potential_quote')->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('quoted_at')->nullable();

            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement('CREATE UNIQUE INDEX idx_csa_annot_submission_id ON marketing.contact_submission_annotations(contact_submission_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing.contact_submission_annotations');
    }
};
