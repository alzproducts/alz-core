<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_service.call_tracking_rotation_counter', static function (Blueprint $table): void {
            $table->smallInteger('id')->primary()->default(1);
            $table->integer('counter')->default(0);
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement('
            ALTER TABLE customer_service.call_tracking_rotation_counter
                ADD CONSTRAINT chk_ct_rotation_counter_single_row CHECK (id = 1)
        ');

        DB::table('customer_service.call_tracking_rotation_counter')->insert([
            'id' => 1,
            'counter' => 0,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_service.call_tracking_rotation_counter');
    }
};
