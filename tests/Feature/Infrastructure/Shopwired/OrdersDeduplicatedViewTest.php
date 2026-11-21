<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Shopwired;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Schema test for migration 2026_11_21_100000: `payment_method_raw` reaches
 * `shopwired.orders_deduplicated`, and the views built on it survive the re-create.
 */
#[CoversNothing]
final class OrdersDeduplicatedViewTest extends TestCase
{
    use DatabaseTransactions;

    private const int RAW_ORDER_ID = 2147460001;

    private const int NULL_ORDER_ID = 2147460002;

    /** @var list<string> */
    private const array DEPENDENT_VIEWS = [
        'catalog.orders_view',
        'catalog.product_popularity_ranking',
        'catalog.sku_popularity_ranking',
        'catalog.credit_product_popularity_ranking',
    ];

    #[Test]
    public function orders_table_has_nullable_varchar_100_payment_method_raw(): void
    {
        $column = DB::selectOne(<<<'SQL'
            SELECT data_type, character_maximum_length, is_nullable
            FROM information_schema.columns
            WHERE table_schema = 'shopwired' AND table_name = 'orders' AND column_name = 'payment_method_raw'
            SQL);

        self::assertNotNull($column, 'shopwired.orders.payment_method_raw is missing');
        self::assertSame('character varying', $column->data_type);
        self::assertSame(100, $column->character_maximum_length);
        self::assertSame('YES', $column->is_nullable);
    }

    #[Test]
    public function payment_method_raw_is_the_last_column_of_the_deduplicated_view(): void
    {
        $last = DB::selectOne(<<<'SQL'
            SELECT column_name
            FROM information_schema.columns
            WHERE table_schema = 'shopwired' AND table_name = 'orders_deduplicated'
            ORDER BY ordinal_position DESC
            LIMIT 1
            SQL);

        self::assertNotNull($last);
        self::assertSame('payment_method_raw', $last->column_name);
    }

    #[Test]
    public function deduplicated_view_returns_each_rows_payment_method_raw(): void
    {
        $this->insertOrder(self::RAW_ORDER_ID, 'Opayo Hosted');
        $this->insertOrder(self::NULL_ORDER_ID, null);

        self::assertSame('Opayo Hosted', $this->viewRawPaymentMethod(self::RAW_ORDER_ID));
        self::assertNull($this->viewRawPaymentMethod(self::NULL_ORDER_ID));
    }

    #[Test]
    public function views_depending_on_the_deduplicated_view_still_exist(): void
    {
        foreach (self::DEPENDENT_VIEWS as $view) {
            $exists = DB::selectOne('SELECT to_regclass(?) IS NOT NULL AS present', [$view]);

            self::assertNotNull($exists);
            self::assertTrue($exists->present, "{$view} no longer exists");
        }
    }

    private function viewRawPaymentMethod(int $externalId): ?string
    {
        $row = DB::selectOne(
            'SELECT payment_method_raw FROM shopwired.orders_deduplicated WHERE external_id = ?',
            [$externalId],
        );

        self::assertNotNull($row, "order {$externalId} missing from the view");

        /** @var string|null */
        return $row->payment_method_raw;
    }

    private function insertOrder(int $externalId, ?string $rawPaymentMethod): void
    {
        DB::table('shopwired.orders')->insert([
            'external_id' => $externalId,
            'reference' => $externalId,
            'total' => '10.000000',
            'sub_total_net' => '10.000000',
            'shipping_total_net' => '0.000000',
            'status_name' => 'Processing',
            'status_type' => 'processing',
            'lifecycle_status' => 'processing',
            'customer_id' => 1,
            'customer_type' => 1,
            'billing_name' => 'Test Buyer',
            'billing_email' => 'buyer@example.test',
            'billing_address_line1' => '1 Test Street',
            'billing_city' => 'Testville',
            'billing_postcode' => 'TE1 1ST',
            'billing_country' => 'United Kingdom',
            'delivery_name' => 'Test Buyer',
            'delivery_email' => 'buyer@example.test',
            'delivery_address_line1' => '1 Test Street',
            'delivery_city' => 'Testville',
            'delivery_postcode' => 'TE1 1ST',
            'delivery_country' => 'United Kingdom',
            'shipping_charge_net' => '0.000000',
            'payment_method' => 'card',
            'payment_method_raw' => $rawPaymentMethod,
            'order_placed_at' => '2026-11-01 10:00:00+00',
            'created_at' => '2026-11-01 10:00:00+00',
            'updated_at' => '2026-11-01 10:00:00+00',
        ]);
    }
}
