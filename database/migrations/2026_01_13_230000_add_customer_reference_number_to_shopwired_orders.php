<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds customer_reference_number column to shopwired.orders table.
 *
 * Issue #112: Customer reference number is extracted from order comments
 * using business rules (case-insensitive "Reference " keyword, excludes
 * structured comments with delimiters).
 *
 * The value is extracted at API sync time and stored for efficient querying
 * without needing to re-parse comments on every access.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('shopwired.orders', static function (Blueprint $table): void {
            $table->string('customer_reference_number', 255)->nullable()->after('comments');
        });
    }

    public function down(): void
    {
        Schema::table('shopwired.orders', static function (Blueprint $table): void {
            $table->dropColumn('customer_reference_number');
        });
    }
};
