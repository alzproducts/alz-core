<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired\Clients;

use App\Domain\Catalog\Order\ValueObjects\Order;
use App\Domain\Catalog\Order\ValueObjects\OrderProduct;
use App\Infrastructure\Shopwired\Clients\OrderClient;
use App\Infrastructure\Shopwired\Contracts\ShopwiredTransportInterface;
use App\Infrastructure\Shopwired\Responses\OrderProductResponse;
use App\Infrastructure\Shopwired\Responses\OrderResponse;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(OrderClient::class)]
#[CoversClass(OrderResponse::class)]
#[CoversClass(OrderProductResponse::class)]
final class OrderClientTest extends TestCase
{
    private ShopwiredTransportInterface&MockInterface $transport;

    private OrderClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = Mockery::mock(ShopwiredTransportInterface::class);
        $this->client = new OrderClient($this->transport);
        Log::spy();
    }

    #[Test]
    public function it_skips_a_zero_product_id_line_and_keeps_every_order_on_the_page(): void
    {
        $this->givenPage([
            self::order(101, 5001, [self::line(11, 'SKU-A'), self::line(0, 'MANUAL', 'Manual line')]),
            self::order(102, 5002, [self::line(12, 'SKU-B')]),
        ]);

        $orders = $this->firstPage();

        self::assertSame([101, 102], \array_map(static fn(Order $o): int => $o->id, $orders));
        self::assertSame(['SKU-A'], self::skus($orders[0]));
        self::assertSame(['SKU-B'], self::skus($orders[1]));
    }

    #[Test]
    public function it_logs_one_warning_with_the_order_and_line_details_for_a_skipped_line(): void
    {
        $this->givenPage([
            self::order(101, 5001, [self::line(11, 'SKU-A'), self::line(0, 'MANUAL', 'Manual line', 3)]),
        ]);

        $this->firstPage();

        Log::shouldHaveReceived('warning')->once()->with(
            Mockery::type('string'),
            [
                'order_id' => 101,
                'order_reference' => 5001,
                'product_id' => 0,
                'title' => 'Manual line',
                'sku' => 'MANUAL',
                'quantity' => 3,
            ],
        );
    }

    #[Test]
    public function it_skips_and_logs_a_negative_product_id_line(): void
    {
        $this->givenPage([
            self::order(101, 5001, [self::line(-4, 'NEG'), self::line(11, 'SKU-A')]),
        ]);

        $orders = $this->firstPage();

        self::assertSame(['SKU-A'], self::skus($orders[0]));
        Log::shouldHaveReceived('warning')->once()->with(
            Mockery::type('string'),
            Mockery::on(static fn(array $context): bool => $context['order_id'] === 101 && $context['product_id'] === -4),
        );
    }

    #[Test]
    public function it_keeps_every_line_and_logs_nothing_when_all_product_ids_are_valid(): void
    {
        $this->givenPage([
            self::order(101, 5001, [self::line(11, 'SKU-A'), self::line(12, 'SKU-B')]),
        ]);

        $orders = $this->firstPage();

        self::assertSame(['SKU-A', 'SKU-B'], self::skus($orders[0]));
        Log::shouldNotHaveReceived('warning');
    }

    /**
     * @param list<array<string, mixed>> $orders
     */
    private function givenPage(array $orders): void
    {
        $this->transport->shouldReceive('get')
            ->once()
            ->andReturn(new Response(new Psr7Response(200, [], (string) \json_encode($orders))));
    }

    /**
     * @return list<Order>
     */
    private function firstPage(): array
    {
        foreach ($this->client->iterateOrderBatches(maxPages: 1) as $orders) {
            return $orders;
        }

        self::fail('Expected one page of orders');
    }

    /**
     * @return list<string>
     */
    private static function skus(Order $order): array
    {
        return \array_map(static fn(OrderProduct $p): string => $p->sku, $order->products ?? []);
    }

    /**
     * @param list<array<string, mixed>> $products
     *
     * @return array<string, mixed>
     */
    private static function order(int $id, int $reference, array $products): array
    {
        $address = [
            'name' => 'Test Customer',
            'email_address' => 'customer@example.test',
            'telephone' => null,
            'company_name' => null,
            'address_line_1' => '1 Test Street',
            'address_line_2' => null,
            'address_line_3' => null,
            'city' => 'London',
            'province' => null,
            'state' => null,
            'postcode' => 'SW1A 1AA',
            'country' => 'United Kingdom',
            'country_id' => 1,
        ];

        return [
            'id' => $id,
            'reference' => $reference,
            'created' => '2026-01-15T10:00:00+00:00',
            'archived' => false,
            'anonymized' => false,
            'pre_order' => false,
            'payment_method' => 'PayPal',
            'total' => 12.0,
            'sub_total' => 10.0,
            'shipping_total' => 0.0,
            'original_shipping_total' => 0.0,
            'partial_payment_total' => 0.0,
            'marketing' => false,
            'comments' => null,
            'tracking_url' => null,
            'referrer_id' => 0,
            'earned_reward_points' => 0.0,
            'line_item_vat_calculation' => false,
            'status' => ['id' => 1, 'name' => 'Paid', 'type' => 'paid', 'sort_order' => 1],
            'billing_address' => $address,
            'shipping_address' => $address,
            'customer' => ['id' => 1, 'type' => 1, 'date_of_birth' => null],
            'products' => $products,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function line(int $productId, string $sku, string $title = 'Product', int $quantity = 1): array
    {
        return [
            'id' => $productId,
            'title' => $title,
            'sku' => $sku,
            'price' => 10.0,
            'price_vat' => 2.0,
            'total' => 10.0,
            'total_vat' => 2.0,
            'original_price' => 10.0,
            'quantity' => $quantity,
            'vat_rate' => 20.0,
        ];
    }
}
