<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('customer_service.call_tracking_calls', static function (Blueprint $table): void {
            $table->string('call_sid', 34)->unique()->after('id');
            $table->timestampTz('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customer_service.call_tracking_calls', static function (Blueprint $table): void {
            $table->dropUnique(['call_sid']);
            $table->dropColumn(['call_sid', 'updated_at']);
        });
    }
};
