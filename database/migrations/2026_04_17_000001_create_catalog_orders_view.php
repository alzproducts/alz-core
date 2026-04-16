<?php

declare(strict_types=1);

use App\Domain\Catalog\Order\View\ValueObjects\OrderView;
use App\Infrastructure\Catalog\Order\Models\OrderViewModel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Create read-side orders view for API consumption.
 *
 * Built ON TOP of shopwired.orders_deduplicated so edited-order duplicates
 * are filtered out automatically. Projects only the columns the read-side
 * API needs (slim OrderView + OrderCustomerSummary).
 *
 * @see OrderView
 * @see OrderViewModel
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW catalog.orders_view AS
            SELECT
                id,
                external_id,
                reference,
                order_placed_at AS placed_at,
                total,
                status_id,
                status_name,
                status_type,
                status_sort_order,
                lifecycle_status,
                billing_email,
                billing_name,
                customer_id
            FROM shopwired.orders_deduplicated
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS catalog.orders_view');
    }
};
