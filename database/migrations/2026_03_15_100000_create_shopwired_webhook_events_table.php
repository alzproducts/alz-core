<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Create shopwired.webhook_events table for centralised webhook idempotency.
 *
 * Replaces per-entity shopwired_webhook_at columns with a dedicated table that
 * tracks webhook events by (subject_id, topic, webhook_id). Uses ShopWired's
 * monotonically increasing webhook_id for ordering instead of timestamps.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('shopwired.webhook_events', static function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            $table->integer('subject_id');
            $table->string('topic', 50);
            $table->bigInteger('webhook_id')->unique();
            $table->timestampTz('event_time');

            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');

            $table->index(['subject_id', 'topic', 'webhook_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopwired.webhook_events');
    }
};
