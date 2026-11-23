<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\QuickBooks;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Tests\TestCase;

#[CoversNothing]
final class OrderSyncSourceViewTest extends TestCase
{
    use DatabaseTransactions;

    private const int EDITED_REFERENCE = 2147450201;

    private const int CANCELLED_ORIGINAL_ID = 2147450201;

    private const int LIVE_EDIT_ID = 2147450202;

    private const int CANCELLED_ONLY_ID = 2147450203;

    private const int NO_LINES_ID = 2147450204;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->insertOrder(self::CANCELLED_ORIGINAL_ID, self::EDITED_REFERENCE, 'cancelled');
        $liveOrderId = $this->insertOrder(self::LIVE_EDIT_ID, self::EDITED_REFERENCE, 'processing');
        $this->insertOrder(self::CANCELLED_ONLY_ID, self::CANCELLED_ONLY_ID, 'cancelled');
        $this->insertOrder(self::NO_LINES_ID, self::NO_LINES_ID, 'processing');

        $this->insertLine($liveOrderId, self::LIVE_EDIT_ID, 30, 'SKU-VAR-B', 'var-b');
        $this->insertLine($liveOrderId, self::LIVE_EDIT_ID, 10, 'SKU-PLAIN', null);
        $this->insertLine($liveOrderId, self::LIVE_EDIT_ID, 30, 'SKU-VAR-BASE', null);
        DB::table('shopwired.order_product_extra_data')->insert([
            'order_external_id' => self::LIVE_EDIT_ID,
            'external_id' => 10,
            'variation_hash' => null,
            'sku_override' => 'SKU-OVERRIDDEN',
        ]);
    }

    #[Test]
    public function the_view_has_exactly_the_sync_source_columns(): void
    {
        $columns = DB::table('information_schema.columns')
            ->where('table_schema', 'quickbooks')
            ->where('table_name', 'order_sync_source')
            ->orderBy('ordinal_position')
            ->pluck('column_name')
            ->all();

        self::assertSame([
            'reference', 'external_id', 'order_placed_at', 'lifecycle_status', 'payment_method_raw',
            'billing_name', 'billing_company', 'billing_address_line1', 'billing_address_line2',
            'billing_address_line3', 'billing_city', 'billing_postcode', 'billing_country',
            'billing_email', 'billing_telephone',
            'delivery_name', 'delivery_company', 'delivery_address_line1', 'delivery_address_line2',
            'delivery_address_line3', 'delivery_city', 'delivery_postcode', 'delivery_country',
            'shipping_method', 'shipping_total_net', 'shipping_vat_rate', 'comments', 'transaction_id', 'lines',
        ], $columns);
    }

    #[Test]
    public function a_shared_reference_returns_one_row_for_the_live_edit(): void
    {
        $rows = DB::select('SELECT external_id FROM quickbooks.order_sync_source WHERE reference = ?', [self::EDITED_REFERENCE]);

        self::assertCount(1, $rows);
        self::assertSame(self::LIVE_EDIT_ID, $rows[0]->external_id);
    }

    #[Test]
    public function a_cancelled_only_order_and_an_order_with_no_lines_are_kept(): void
    {
        self::assertSame('cancelled', self::sourceRow(self::CANCELLED_ONLY_ID)->lifecycle_status);
        self::assertSame([], self::lines(self::sourceRow(self::NO_LINES_ID)));
    }

    #[Test]
    public function money_and_rates_are_text_at_the_source_scale(): void
    {
        $row = self::sourceRow(self::EDITED_REFERENCE);

        self::assertSame('4.990000', $row->shipping_total_net);
        self::assertSame('20.0', $row->shipping_vat_rate);

        $line = self::lines($row)[0];
        self::assertSame('12.500000', $line['price']);
        self::assertSame('2.500000', $line['price_vat']);
        self::assertSame('20.0', $line['vat_rate']);
    }

    #[Test]
    public function lines_carry_exactly_the_contract_keys_in_line_order_with_the_sku_override(): void
    {
        $lines = self::lines(self::sourceRow(self::EDITED_REFERENCE));

        foreach ($lines as $line) {
            $keys = \array_keys($line);
            \sort($keys);
            self::assertSame(['price', 'price_vat', 'quantity', 'sku', 'title', 'vat_rate'], $keys);
        }

        self::assertSame(['SKU-OVERRIDDEN', 'SKU-VAR-BASE', 'SKU-VAR-B'], \array_column($lines, 'sku'));
        self::assertSame(['Line 10', 'Line 30', 'Line 30'], \array_column($lines, 'title'));
        self::assertSame([2, 2, 2], \array_column($lines, 'quantity'));
    }

    private static function sourceRow(int $reference): stdClass
    {
        $row = DB::selectOne('SELECT * FROM quickbooks.order_sync_source WHERE reference = ?', [$reference]);
        self::assertInstanceOf(stdClass::class, $row, "reference {$reference} missing from the view");

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function lines(stdClass $row): array
    {
        self::assertIsString($row->lines);
        $lines = \json_decode($row->lines, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($lines);
        self::assertTrue(\array_is_list($lines));

        /** @var list<array<string, mixed>> $lines */
        return $lines;
    }

    private function insertOrder(int $externalId, int $reference, string $lifecycleStatus): string
    {
        $id = DB::table('shopwired.orders')->insertGetId([
            'external_id' => $externalId,
            'reference' => $reference,
            'total' => '29.990000',
            'sub_total_net' => '25.000000',
            'shipping_total_net' => '4.990000',
            'shipping_vat_rate' => '20.0',
            'status_name' => $lifecycleStatus === 'cancelled' ? 'Cancelled' : 'Processing',
            'status_type' => $lifecycleStatus,
            'lifecycle_status' => $lifecycleStatus,
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
            'shipping_charge_net' => '4.990000',
            'payment_method' => 'card',
            'order_placed_at' => '2026-11-01 10:00:00+00',
            'created_at' => '2026-11-01 10:00:00+00',
            'updated_at' => '2026-11-01 10:00:00+00',
        ]);
        self::assertIsString($id);

        return $id;
    }

    private function insertLine(string $orderId, int $orderExternalId, int $externalId, string $sku, ?string $variationHash): void
    {
        DB::table('shopwired.order_products')->insert([
            'order_id' => $orderId,
            'order_external_id' => $orderExternalId,
            'external_id' => $externalId,
            'title' => "Line {$externalId}",
            'sku' => $sku,
            'price' => '12.5',
            'price_vat' => '2.5',
            'total' => '25.0',
            'total_vat' => '5.0',
            'original_price' => '12.5',
            'quantity' => 2,
            'vat_rate' => '20',
            'variation_hash' => $variationHash,
            'created_at' => '2026-11-01 10:00:00+00',
            'updated_at' => '2026-11-01 10:00:00+00',
        ]);
    }
}
