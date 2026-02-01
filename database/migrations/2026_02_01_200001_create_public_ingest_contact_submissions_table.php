<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the public_ingest.contact_submissions table for form submissions.
 *
 * This is an immutable snapshot table - submissions are never updated.
 * Processing state is tracked separately in customer_service.contact_submission_actions.
 *
 * Key design decisions:
 * - Attribution columns (GCLID, UTM) are flattened for B-tree indexing
 * - Product context uses JSONB for variable structure
 * - No updated_at (immutable records)
 *
 * @depends 2026_02_01_200000_create_public_ingest_schema
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('public_ingest.contact_submissions', static function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // Core form (flattened for querying)
            $table->string('name', 255);
            $table->string('email', 255);
            $table->string('reason', 50);
            $table->text('message');
            $table->string('phone', 50)->nullable();
            $table->string('customer_type', 50)->nullable();
            $table->string('order_number', 20)->nullable();
            $table->string('delivery_postcode', 20)->nullable();
            $table->smallInteger('quantity')->nullable();

            // Product context (JSONB - optional, variable structure)
            $table->jsonb('product')->nullable();

            // User identification
            $table->string('shopwired_customer_id', 50)->nullable();

            // Consent (separate columns for compliance filtering)
            $table->boolean('consent_marketing')->default(false);
            $table->boolean('consent_statistics')->default(false);
            $table->boolean('consent_preferences')->default(false);
            $table->boolean('consent_has_responded')->default(false);

            // Attribution (separate columns for queryability - needed for conversion tracking)
            $table->string('gclid', 255)->nullable();
            $table->string('utm_source', 255)->nullable();
            $table->string('utm_medium', 255)->nullable();
            $table->string('utm_campaign', 255)->nullable();
            $table->string('utm_content', 255)->nullable();
            $table->string('utm_term', 255)->nullable();

            // Context
            $table->text('page_url');
            $table->text('referrer_url')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('client_timestamp');
            $table->ipAddress('ip_address');

            // Timestamp (immutable - no updated_at)
            $table->timestampTz('created_at')->useCurrent();
        });

        // Comments for internal columns
        DB::statement("COMMENT ON COLUMN public_ingest.contact_submissions.ip_address IS 'Internal: captured for rate limiting and fraud detection'");
        DB::statement("COMMENT ON COLUMN public_ingest.contact_submissions.gclid IS 'Internal: Google Ads click ID for conversion attribution'");
        DB::statement("COMMENT ON COLUMN public_ingest.contact_submissions.consent_marketing IS 'Consent status at submission time for compliance audit'");

        // Indexes
        DB::statement('CREATE INDEX idx_contact_submissions_email ON public_ingest.contact_submissions(email)');
        DB::statement('CREATE INDEX idx_contact_submissions_reason ON public_ingest.contact_submissions(reason)');
        DB::statement('CREATE INDEX idx_contact_submissions_created_at ON public_ingest.contact_submissions(created_at)');
        DB::statement('CREATE INDEX idx_contact_submissions_gclid ON public_ingest.contact_submissions(gclid) WHERE gclid IS NOT NULL');
        DB::statement('CREATE INDEX idx_contact_submissions_order_number ON public_ingest.contact_submissions(order_number) WHERE order_number IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('public_ingest.contact_submissions');
    }
};
