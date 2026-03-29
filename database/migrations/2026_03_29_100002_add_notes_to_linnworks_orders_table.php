<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('linnworks.orders', static function (Blueprint $table): void {
            $table->jsonb('notes')->nullable()->after('folder_names');
        });
    }

    public function down(): void
    {
        Schema::table('linnworks.orders', static function (Blueprint $table): void {
            $table->dropColumn('notes');
        });
    }
};
