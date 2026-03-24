<?php

declare(strict_types=1);

use App\Infrastructure\Shopwired\Models\OrderProductModel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shopwired.order_products', static function (Blueprint $table): void {
            $table->string('variation_hash', 32)->nullable()->after('variation');
            $table->index(
                ['order_external_id', 'external_id', 'variation_hash'],
                'order_products_oed_eid_vhash_idx',
            );
        });

        // Backfill variation_hash for existing rows using PHP to ensure
        // hash output matches the application-level computeLineItemHash().
        // Batch UPDATE per chunk to avoid N+1 writes on large tables.
        OrderProductModel::query()
            ->chunkById(1000, static function ($products): void {
                $caseClauses = [];
                $bindings = [];
                $ids = [];

                foreach ($products as $product) {
                    /** @var OrderProductModel $product */
                    $hash = OrderProductModel::computeLineItemHash($product);
                    $caseClauses[] = 'WHEN id = ? THEN ?';
                    $bindings[] = $product->id;
                    $bindings[] = $hash;
                    $ids[] = $product->id;
                }

                $caseExpr = implode(' ', $caseClauses);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));

                DB::update(
                    "UPDATE shopwired.order_products
                     SET variation_hash = CASE {$caseExpr} END,
                         updated_at = NOW()
                     WHERE id IN ({$placeholders})",
                    array_merge($bindings, $ids),
                );
            });
    }

    public function down(): void
    {
        // dropIndex() omits the schema qualifier, causing PostgreSQL to fail when
        // the search_path doesn't include 'shopwired'. Use raw DDL instead.
        DB::statement('DROP INDEX IF EXISTS shopwired."order_products_oed_eid_vhash_idx"');

        Schema::table('shopwired.order_products', static function (Blueprint $table): void {
            $table->dropColumn('variation_hash');
        });
    }
};
