<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allow duplicate order references in shopwired.orders table.
 *
 * Problem: When orders are "edited" in ShopWired, the system creates a NEW order
 * with a new external_id but the SAME customer-facing reference number. The original
 * order is marked as cancelled. The unique constraint on `reference` caused sync
 * failures - the first order (cancelled) won, rejecting the active replacement.
 *
 * Solution: Drop unique constraint, keep index for query performance. The repository's
 * getByReference() method handles duplicate resolution (prefers non-cancelled, then
 * highest external_id).
 *
 * @see \App\Infrastructure\Shopwired\Repositories\EloquentOrderRepository::getByReference()
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('shopwired.orders', static function (Blueprint $table): void {
            // Drop unique constraint (this also drops the implicit unique index)
            $table->dropUnique(['reference']);

            // Add regular index for query performance on reference lookups
            $table->index('reference');
        });
    }

    public function down(): void
    {
        Schema::table('shopwired.orders', static function (Blueprint $table): void {
            // Remove regular index
            $table->dropIndex(['reference']);

            // Restore unique constraint
            // Note: This will fail if duplicate references exist in the data
            $table->unique('reference');
        });
    }
};
