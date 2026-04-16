<?php

declare(strict_types=1);

use App\Domain\Customer\View\ValueObjects\CustomerView;
use App\Infrastructure\Customer\Models\CustomerViewModel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Create read-side customers view for API consumption.
 *
 * Passthrough view over shopwired.customers, renaming shopwired_created_at to
 * created_at so the Eloquent ViewModel can use standard conventions. Projects
 * only the columns needed by the slim CustomerView.
 *
 * @see CustomerView
 * @see CustomerViewModel
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW catalog.customers_view AS
            SELECT
                id,
                external_id,
                email,
                first_name,
                last_name,
                is_trade,
                is_active,
                shopwired_created_at AS created_at
            FROM shopwired.customers
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS catalog.customers_view');
    }
};
