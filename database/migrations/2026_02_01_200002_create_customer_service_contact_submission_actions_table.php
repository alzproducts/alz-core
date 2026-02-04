<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the customer_service schema and contact_submission_actions table.
 *
 * The customer_service schema is for internal customer service operations.
 * The contact_submission_actions table tracks processing state for each action
 * taken on a contact submission (e.g., creating HelpScout ticket).
 *
 * Key design decisions:
 * - Mutable table (unlike immutable contact_submissions)
 * - action_type column for extensibility (helpscout, mixpanel, slack, etc.)
 * - CASCADE DELETE for GDPR erasure compliance
 * - processing_started_at for stale detection
 *
 * @depends 2026_02_01_200001_create_public_ingest_contact_submissions_table
 */
return new class extends Migration {
    public function up(): void
    {
        // Create customer_service schema if it doesn't exist
        $schemaExists = DB::selectOne(
            "SELECT EXISTS (SELECT 1 FROM pg_namespace WHERE nspname = 'customer_service') as exists",
        );

        if ($schemaExists === null || ! $schemaExists->exists) {
            DB::statement('CREATE SCHEMA IF NOT EXISTS customer_service');

            // Grant schema usage to Supabase roles
            DB::statement('GRANT USAGE ON SCHEMA customer_service TO authenticated, service_role');

            // Set default privileges for tables created in this schema
            DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA customer_service GRANT ALL ON TABLES TO service_role');
            DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA customer_service GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO authenticated');
            DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA customer_service GRANT USAGE, SELECT ON SEQUENCES TO service_role');
            DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA customer_service GRANT USAGE, SELECT ON SEQUENCES TO authenticated');
        }

        Schema::create('customer_service.contact_submission_actions', static function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            // Foreign key to submission (cascade delete for GDPR erasure)
            $table->uuid('contact_submission_id');
            $table->foreign('contact_submission_id')
                ->references('id')
                ->on('public_ingest.contact_submissions')
                ->cascadeOnDelete();

            // Action type (extensible for future actions)
            $table->string('action_type', 50);

            // Processing state
            $table->string('status', 20)->default('pending');

            // External reference (e.g., HelpScout conversation ID)
            $table->string('external_id', 255)->nullable();

            // Error tracking
            $table->text('error_message')->nullable();
            $table->integer('attempts')->default(0);

            // Timestamps
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->timestampTz('processing_started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
        });

        // Indexes
        DB::statement('CREATE INDEX idx_csa_submission_id ON customer_service.contact_submission_actions(contact_submission_id)');
        DB::statement('CREATE INDEX idx_csa_status ON customer_service.contact_submission_actions(status)');
        DB::statement("CREATE INDEX idx_csa_pending ON customer_service.contact_submission_actions(status, action_type) WHERE status = 'pending'");
        DB::statement("CREATE INDEX idx_csa_stale_processing ON customer_service.contact_submission_actions(processing_started_at) WHERE status = 'processing'");

        // Unique constraint: one action per type per submission
        DB::statement('CREATE UNIQUE INDEX idx_csa_unique_action ON customer_service.contact_submission_actions(contact_submission_id, action_type)');

        // CHECK constraint for valid status values
        DB::statement("
            ALTER TABLE customer_service.contact_submission_actions
            ADD CONSTRAINT csa_status_check
            CHECK (status IN ('pending', 'processing', 'completed', 'failed'))
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_service.contact_submission_actions');

        // Only drop schema if empty
        $hasTables = DB::selectOne(
            "SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'customer_service') as exists",
        );

        if ($hasTables === null || ! $hasTables->exists) {
            $this->safeRevoke('ALTER DEFAULT PRIVILEGES IN SCHEMA customer_service REVOKE ALL ON TABLES FROM service_role');
            $this->safeRevoke('ALTER DEFAULT PRIVILEGES IN SCHEMA customer_service REVOKE ALL ON TABLES FROM authenticated');
            $this->safeRevoke('ALTER DEFAULT PRIVILEGES IN SCHEMA customer_service REVOKE ALL ON SEQUENCES FROM service_role');
            $this->safeRevoke('ALTER DEFAULT PRIVILEGES IN SCHEMA customer_service REVOKE ALL ON SEQUENCES FROM authenticated');
            $this->safeRevoke('REVOKE USAGE ON SCHEMA customer_service FROM authenticated, service_role');
            DB::statement('DROP SCHEMA IF EXISTS customer_service');
        }
    }

    private function safeRevoke(string $sql): void
    {
        try {
            DB::statement($sql);
        } catch (Exception) {
            // @ignoreException Role/schema may not exist during rollback
        }
    }
};
