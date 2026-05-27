<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_service.call_tracking_calls', static function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('tracking_number_dialled', 20);
            $table->string('caller_phone_number', 20);
            $table->bigInteger('helpscout_conversation_id')->nullable();
            $table->string('call_status', 20)->default('initiated');
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement("
            ALTER TABLE customer_service.call_tracking_calls
                ADD CONSTRAINT chk_ct_calls_status CHECK (call_status IN ('initiated'))
        ");

        DB::statement('CREATE INDEX idx_ct_calls_tracking_created ON customer_service.call_tracking_calls(tracking_number_dialled, created_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_service.call_tracking_calls');
    }
};
