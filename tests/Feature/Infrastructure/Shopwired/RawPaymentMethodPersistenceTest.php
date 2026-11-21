<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Shopwired;

use App\Application\Contracts\Shopwired\OrderClientInterface;
use App\Application\Contracts\Shopwired\OrderRepositoryInterface;
use App\Application\Shopwired\Enums\OrderQueryMode;
use App\Domain\Catalog\Order\ValueObjects\Order;
use App\Domain\Catalog\Order\ValueObjects\RawPaymentMethod;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Infrastructure\Jobs\Shopwired\SyncShopwiredOrdersRangeJob;
use App\Infrastructure\Shopwired\Clients\OrderClient;
use App\Infrastructure\Shopwired\Contracts\ShopwiredTransportInterface;
use App\Infrastructure\Shopwired\Parsers\ShopwiredOrderWebhookParser;
use DateTimeImmutable;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Mockery;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Infrastructure\Shopwired\Fixtures\ShopwiredOrderPayload;
use Throwable;

/**
 * Runs against the shared Postgres database inside a rolled-back transaction, asserting
 * only on rows this test seeds (external ids in the 9_298_xxx range).
 */
#[CoversNothing]
final class RawPaymentMethodPersistenceTest extends TestCase
{
    use DatabaseTransactions;

    private const string TABLE = 'shopwired.orders';

    private const string CREATED = '2026-03-15T10:00:00+00:00';

    /** @var list<array{exception: Throwable, attempts: int}> */
    private array $jobFailures = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Event::listen(JobFailed::class, function (JobFailed $event): void {
            $this->jobFailures[] = ['exception' => $event->exception, 'attempts' => $event->job->attempts()];
        });
    }

    #[Test]
    public function a_webhook_order_stores_its_raw_payment_method_on_insert_and_on_update(): void
    {
        $this->saveWebhookOrder(9_298_001, 'Opayo Hosted');
        self::assertSame('Opayo Hosted', $this->storedRaw(9_298_001));

        $this->saveWebhookOrder(9_298_001, 'PayPal');
        self::assertSame('PayPal', $this->storedRaw(9_298_001));
    }

    #[Test]
    public function a_range_sync_stores_the_raw_payment_method_on_insert_and_on_update(): void
    {
        $this->bindOrderPage([ShopwiredOrderPayload::order(9_298_011, 'ShopWired Payments', self::CREATED)]);
        $this->runRangeJob();
        self::assertSame('ShopWired Payments', $this->storedRaw(9_298_011));

        $this->bindOrderPage([ShopwiredOrderPayload::order(9_298_011, 'Credit', self::CREATED)]);
        $this->runRangeJob();
        self::assertSame('Credit', $this->storedRaw(9_298_011));
        self::assertSame([], $this->jobFailures);
    }

    #[Test]
    public function a_re_fetch_fills_a_backlog_row_and_a_second_re_fetch_leaves_it_unchanged(): void
    {
        $this->saveWebhookOrder(9_298_021, 'Sagepay Form');
        DB::table(self::TABLE)->where('external_id', 9_298_021)->update(['payment_method_raw' => null]);
        self::assertNull($this->storedRaw(9_298_021));

        $this->bindOrderPage([ShopwiredOrderPayload::order(9_298_021, 'Sagepay Form', self::CREATED)]);
        $this->runRangeJob();
        $afterFirst = $this->storedRow(9_298_021);

        $this->runRangeJob();

        self::assertSame('Sagepay Form', $afterFirst['payment_method_raw']);
        self::assertSame($afterFirst['payment_method_raw'], $this->storedRaw(9_298_021));
        self::assertSame($afterFirst['payment_method'], $this->storedRow(9_298_021)['payment_method']);
        self::assertSame([], $this->jobFailures);
    }

    #[Test]
    public function an_unknown_payment_method_in_a_page_fails_the_job_once_and_saves_none_of_the_page(): void
    {
        $this->bindOrderPage([
            ShopwiredOrderPayload::order(9_298_031, 'Opayo Hosted', self::CREATED),
            ShopwiredOrderPayload::order(9_298_032, 'Klarna', self::CREATED),
        ]);

        $this->runRangeJob();

        self::assertCount(1, $this->jobFailures);
        $failure = $this->jobFailures[0];
        self::assertInstanceOf(InvalidApiResponseException::class, $failure['exception']);
        self::assertSame('Unknown payment method: Klarna', $failure['exception']->detail);
        self::assertSame(1, $failure['attempts']);
        self::assertSame(0, DB::table(self::TABLE)->whereIn('external_id', [9_298_031, 9_298_032])->count());
    }

    #[Test]
    public function a_stored_raw_payment_method_reads_back_as_its_enum_case(): void
    {
        $this->saveWebhookOrder(9_298_041, 'PayPal');

        self::assertSame(RawPaymentMethod::PayPal, $this->readBack(9_298_041)->rawPaymentMethod);
    }

    #[Test]
    public function a_null_raw_payment_method_reads_back_as_null(): void
    {
        $this->saveWebhookOrder(9_298_051, 'PayPal');
        DB::table(self::TABLE)->where('external_id', 9_298_051)->update(['payment_method_raw' => null]);

        self::assertNull($this->readBack(9_298_051)->rawPaymentMethod);
    }

    #[Test]
    public function an_unrecognised_stored_raw_payment_method_reads_back_as_null_and_logs_an_error(): void
    {
        $this->saveWebhookOrder(9_298_061, 'PayPal');
        DB::table(self::TABLE)->where('external_id', 9_298_061)->update(['payment_method_raw' => 'Bogus']);
        Log::spy();

        $order = $this->readBack(9_298_061);

        self::assertNull($order->rawPaymentMethod);
        Log::shouldHaveReceived('error')
            ->once()
            ->with('Unknown ' . RawPaymentMethod::class . ' in database - possible API change', Mockery::type('array'));
    }

    private function saveWebhookOrder(int $id, string $paymentMethod): void
    {
        $order = new ShopwiredOrderWebhookParser()->parseOrder(['object' => ShopwiredOrderPayload::order($id, $paymentMethod, self::CREATED)]);
        self::assertNotNull($order);

        $this->repository()->saveFromWebhook($order);
    }

    /**
     * @param list<array<string, mixed>> $page
     */
    private function bindOrderPage(array $page): void
    {
        $transport = Mockery::mock(ShopwiredTransportInterface::class);
        $transport->allows('get')->andReturn(new Response(new PsrResponse(200, [], \json_encode($page, JSON_THROW_ON_ERROR))));

        $this->app->instance(OrderClientInterface::class, new OrderClient($transport));
    }

    private function runRangeJob(): void
    {
        SyncShopwiredOrdersRangeJob::dispatch(
            new DateTimeImmutable('2026-03-15T00:00:00+00:00'),
            new DateTimeImmutable('2026-03-15T23:59:59+00:00'),
        )->onConnection('sync');
    }

    private function readBack(int $id): Order
    {
        $orders = $this->repository()->getOrdersInDateRange(
            new DateTimeImmutable('2026-03-15T00:00:00+00:00'),
            new DateTimeImmutable('2026-03-15T23:59:59+00:00'),
            OrderQueryMode::Raw,
        );
        $order = \array_find($orders, static fn(Order $o): bool => $o->id === $id);
        self::assertInstanceOf(Order::class, $order);

        return $order;
    }

    private function storedRaw(int $id): ?string
    {
        $value = $this->storedRow($id)['payment_method_raw'];
        self::assertTrue($value === null || \is_string($value));

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function storedRow(int $id): array
    {
        $row = DB::table(self::TABLE)->where('external_id', $id)->first();
        self::assertNotNull($row);

        return (array) $row;
    }

    private function repository(): OrderRepositoryInterface
    {
        return $this->app->make(OrderRepositoryInterface::class);
    }
}
