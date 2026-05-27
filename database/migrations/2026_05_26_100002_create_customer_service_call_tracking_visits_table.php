<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_service.call_tracking_visits', static function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));

            $table->string('gclid', 255)->nullable();
            $table->string('gclsrc', 255)->nullable();
            $table->string('wbraid', 255)->nullable();
            $table->string('gbraid', 255)->nullable();
            $table->string('msclkid', 255)->nullable();
            $table->string('fbclid', 255)->nullable();
            $table->string('utm_source', 255)->nullable();
            $table->string('utm_medium', 255)->nullable();
            $table->string('utm_campaign', 255)->nullable();
            $table->string('utm_content', 255)->nullable();
            $table->string('utm_term', 255)->nullable();

            $table->boolean('marketing_consent_granted');
            $table->string('tracking_number_shown', 20);
            $table->ipAddress('ip_address');
            $table->text('user_agent')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('CREATE INDEX idx_ct_visits_tracking_created ON customer_service.call_tracking_visits(tracking_number_shown, created_at)');
        DB::statement('CREATE INDEX idx_ct_visits_gclid ON customer_service.call_tracking_visits(gclid) WHERE gclid IS NOT NULL');
        DB::statement('CREATE INDEX idx_ct_visits_msclkid ON customer_service.call_tracking_visits(msclkid) WHERE msclkid IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_service.call_tracking_visits');
    }
};
