<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the catalog.margin_tier_thresholds table.
 *
 * Single-row config table (CHECK id = 1) storing the band boundaries used by
 * the margin-tier sync (COR-148):
 *   margin < low_max_pct          → "1 - Low margin"
 *   low_max_pct <= margin < std   → "2 - Standard margin"
 *   margin >= standard_max_pct    → "3 - High margin"
 *   margin IS NULL                → "4 - Unknown margin"
 *
 * intentional: single-row, not the versioned-config shape of neighbouring algorithm
 * tables. Thresholds are two parameters, with no algorithm to version.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('catalog.margin_tier_thresholds', static function (Blueprint $table): void {
            $table->smallInteger('id')->primary()->default(1);
            $table->decimal('low_max_pct', 5, 2);
            $table->decimal('standard_max_pct', 5, 2);
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement('
            ALTER TABLE catalog.margin_tier_thresholds
                ADD CONSTRAINT chk_margin_thresholds_single_row CHECK (id = 1),
                ADD CONSTRAINT chk_margin_thresholds_ordered    CHECK (low_max_pct < standard_max_pct)
        ');

        DB::table('catalog.margin_tier_thresholds')->insert([
            'id' => 1,
            'low_max_pct' => 20.00,
            'standard_max_pct' => 40.00,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog.margin_tier_thresholds');
    }
};
