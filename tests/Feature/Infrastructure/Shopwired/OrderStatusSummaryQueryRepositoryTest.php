<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Shopwired;

use App\Application\Contracts\Shopwired\OrderStatusSummaryQueryRepositoryInterface;
use App\Application\Shopwired\DTOs\OrderStatusSummaryDTO;
use App\Infrastructure\Shopwired\Repositories\OrderStatusSummaryQueryRepository;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(OrderStatusSummaryQueryRepository::class)]
final class OrderStatusSummaryQueryRepositoryTest extends TestCase
{
    use DatabaseTransactions;

    private const int NOT_PAID = 73875;

    private const int PAID = 73877;

    private const int RECEIVED = 180255;

    private const int BASE_ID = 2147461000;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // Rows already in the database stay outside the summary; the transaction restores them.
        DB::table('shopwired.orders')
            ->whereIn('status_id', [self::NOT_PAID, self::PAID, self::RECEIVED])
            ->update(['status_id' => null]);
    }

    #[Test]
    public function it_returns_zeros_and_an_empty_list_without_matching_orders(): void
    {
        $this->insertOrder(1, 73876);

        self::assertEquals(new OrderStatusSummaryDTO(0, [], 0), $this->summary());
    }

    #[Test]
    public function it_counts_unarchived_paid_and_received_orders_of_any_age(): void
    {
        $this->insertOrder(1, self::PAID);
        $this->insertOrder(2, self::PAID, ['order_placed_at' => CarbonImmutable::now()->subDays(900)]);
        $this->insertOrder(3, self::PAID, ['is_archived' => true]);
        $this->insertOrder(4, self::RECEIVED);
        $this->insertOrder(5, self::RECEIVED, ['is_archived' => true]);
        $this->insertOrder(6, 73876);

        self::assertEquals(new OrderStatusSummaryDTO(2, [], 1), $this->summary());
    }

    #[Test]
    public function it_lists_all_101_unpaid_orders_and_leaves_out_archived_and_older_ones(): void
    {
        $placedAt = CarbonImmutable::now()->subDays(10);
        $expected = [];
        for ($i = 1; $i <= 101; $i++) {
            $this->insertOrder($i, self::NOT_PAID, ['order_placed_at' => $placedAt->addMinutes($i)]);
            $expected[] = (string) (self::BASE_ID + $i);
        }
        $this->insertOrder(200, self::NOT_PAID, ['is_archived' => true]);
        $this->insertOrder(201, self::NOT_PAID, ['order_placed_at' => CarbonImmutable::now()->subDays(701)]);

        self::assertSame($expected, $this->summary()->unpaidOrderReferences);
    }

    #[Test]
    public function it_orders_by_placed_at_then_external_id_and_keeps_the_699_day_old_order(): void
    {
        $sameTime = CarbonImmutable::now()->subDays(5);
        $this->insertOrder(3, self::NOT_PAID, ['reference' => 900001, 'order_placed_at' => $sameTime]);
        $this->insertOrder(2, self::NOT_PAID, ['reference' => 900002, 'order_placed_at' => $sameTime]);
        $this->insertOrder(4, self::NOT_PAID, ['reference' => 900003, 'order_placed_at' => CarbonImmutable::now()->subDays(699)]);

        self::assertSame(['900003', '900002', '900001'], $this->summary()->unpaidOrderReferences);
    }

    #[Test]
    public function it_uses_only_the_selected_row_of_an_edited_order(): void
    {
        $this->insertOrder(1, self::NOT_PAID, ['reference' => 900010]);
        $this->insertOrder(2, self::PAID, ['reference' => 900010]);

        self::assertEquals(new OrderStatusSummaryDTO(1, [], 0), $this->summary());
    }

    #[Test]
    public function it_applies_the_archived_filter_to_the_selected_row(): void
    {
        $this->insertOrder(1, self::RECEIVED, ['reference' => 900011]);
        $this->insertOrder(2, self::RECEIVED, ['reference' => 900011, 'is_archived' => true]);

        self::assertEquals(new OrderStatusSummaryDTO(0, [], 0), $this->summary());
    }

    #[Test]
    public function it_applies_the_date_filter_to_the_selected_row(): void
    {
        $this->insertOrder(1, self::NOT_PAID, ['reference' => 900012]);
        $this->insertOrder(2, self::NOT_PAID, ['reference' => 900012, 'order_placed_at' => CarbonImmutable::now()->subDays(701)]);

        self::assertSame([], $this->summary()->unpaidOrderReferences);
    }

    private function summary(): OrderStatusSummaryDTO
    {
        return $this->app->make(OrderStatusSummaryQueryRepositoryInterface::class)->getSummary();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertOrder(int $offset, int $statusId, array $overrides = []): void
    {
        DB::table('shopwired.orders')->insert([
            'external_id' => self::BASE_ID + $offset,
            'reference' => self::BASE_ID + $offset,
            'total' => '10.000000',
            'sub_total_net' => '10.000000',
            'shipping_total_net' => '0.000000',
            'status_id' => $statusId,
            'status_name' => 'Processing',
            'status_type' => 'processing',
            'lifecycle_status' => 'processing',
            'is_archived' => false,
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
            'order_placed_at' => CarbonImmutable::now()->subDay(),
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
            ...$overrides,
        ]);
    }
}
