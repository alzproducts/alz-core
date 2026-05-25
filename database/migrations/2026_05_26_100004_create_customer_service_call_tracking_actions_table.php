<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_service.call_tracking_actions', static function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            $table->uuid('call_tracking_visit_id');
            $table->foreign('call_tracking_visit_id')
                ->references('id')
                ->on('customer_service.call_tracking_visits')
                ->cascadeOnDelete();

            $table->string('ad_platform', 20);
            $table->string('status', 20)->default('pending');

            $table->string('external_id', 255)->nullable();
            $table->text('error_message')->nullable();
            $table->integer('attempts')->default(0);

            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->timestampTz('processing_started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
        });

        DB::statement("
            ALTER TABLE customer_service.call_tracking_actions
                ADD CONSTRAINT chk_ct_actions_platform CHECK (ad_platform IN ('google', 'bing')),
                ADD CONSTRAINT chk_ct_actions_status CHECK (status IN ('pending', 'processing', 'completed', 'failed'))
        ");

        DB::statement('CREATE UNIQUE INDEX idx_ct_actions_visit_platform ON customer_service.call_tracking_actions(call_tracking_visit_id, ad_platform)');
        DB::statement('CREATE INDEX idx_ct_actions_status ON customer_service.call_tracking_actions(status)');
        DB::statement("CREATE INDEX idx_ct_actions_stale ON customer_service.call_tracking_actions(processing_started_at) WHERE status = 'processing'");
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_service.call_tracking_actions');
    }
};
