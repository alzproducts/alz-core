<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('sync_cursors', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('sync_type')->unique();
            $table->timestamp('cursor_value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_cursors');
    }
};
