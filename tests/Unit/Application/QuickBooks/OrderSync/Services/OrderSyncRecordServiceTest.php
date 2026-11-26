<?php

declare(strict_types=1);

namespace Tests\Unit\Application\QuickBooks\OrderSync\Services;

use App\Application\Contracts\QuickBooks\OrderSyncRepositoryInterface;
use App\Application\QuickBooks\OrderSync\DTOs\ClaimedOrderDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ExistingDocumentDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncChunkDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncClaimDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncOutcomeDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncResultDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SourceOrderRowDTO;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncTrigger;
use App\Application\QuickBooks\OrderSync\Resolvers\SyncOutcomeResolver;
use App\Application\QuickBooks\OrderSync\Services\OrderSyncRecordService;
use App\Domain\Catalog\Order\ValueObjects\OrderLifecycleStatus;
use App\Domain\QuickBooks\OrderSync\Enums\DocType;
use DateTimeImmutable;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\Unit\Application\QuickBooks\OrderSync\SyncOrderFixture;

#[CoversClass(OrderSyncRecordService::class)]
final class OrderSyncRecordServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MockInterface&OrderSyncRepositoryInterface $orderSyncs;

    private MockInterface&LoggerInterface $logger;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->orderSyncs = Mockery::mock(OrderSyncRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
    }

    #[Test]
    public function posted_documents_are_recorded_first_and_results_keep_their_order(): void
    {
        $this->orderSyncs->shouldReceive('recordSynced')->once()->globally()->ordered()->with('sync-3', 'token-3', Mockery::any())->andReturn(true);
        $this->orderSyncs->shouldReceive('release')->once()->globally()->ordered()->with('sync-1', 'token-1')->andReturn(true);
        $this->orderSyncs->shouldReceive('recordFailed')->once()->globally()->ordered()->with('sync-2', 'token-2', Mockery::any())->andReturn(true);

        $results = $this->record([self::released(1), self::failed(2), self::posted(3)]);

        self::assertSame([1, 2, 3], \array_map(static fn(OrderSyncResultDTO $result): int => $result->reference, $results));
    }

    #[Test]
    public function every_outcome_is_tried_and_the_first_throw_is_rethrown(): void
    {
        $first = new RuntimeException('customer data');
        $this->orderSyncs->shouldReceive('recordSynced')->once()->with('sync-1', 'token-1', Mockery::any())->andThrow($first);
        $this->orderSyncs->shouldReceive('recordFailed')->once()->with('sync-2', 'token-2', Mockery::any())->andThrow(new RuntimeException('second'));
        $this->orderSyncs->shouldReceive('release')->once()->with('sync-3', 'token-3')->andReturn(true);
        $this->expectRecordErrorLogged(1, 'already_synced', 'qbo-1');
        $this->expectRecordErrorLogged(2, 'failed', null);

        try {
            $this->record([self::posted(1), self::failed(2), self::released(3)]);
            self::fail('The first throw was swallowed');
        } catch (RuntimeException $e) {
            self::assertSame($first, $e);
        }
    }

    #[Test]
    public function a_lost_lease_is_logged_and_its_result_returned(): void
    {
        $this->orderSyncs->shouldReceive('recordFailed')->once()->andReturn(false);
        $this->logger->shouldReceive('error')->once()->with('QuickBooks order sync lease lost before its outcome was recorded', [
            'reference' => 1,
            'outcome' => 'failed',
            'reason' => 'outcome_unknown',
            'qbo_txn_id' => null,
        ]);

        self::assertSame(1, $this->record([self::failed(1)])[0]->reference);
    }

    private function expectRecordErrorLogged(int $reference, string $outcome, ?string $txnId): void
    {
        $this->logger->shouldReceive('error')->once()->with('QuickBooks order sync outcome could not be recorded', [
            'reference' => $reference,
            'outcome' => $outcome,
            'qbo_txn_id' => $txnId,
            'exception' => RuntimeException::class,
        ]);
    }

    /**
     * @param list<OrderSyncOutcomeDTO> $outcomes
     *
     * @return list<OrderSyncResultDTO>
     */
    private function record(array $outcomes): array
    {
        return new OrderSyncRecordService($this->orderSyncs, $this->logger)
            ->record(new OrderSyncChunkDTO('realm-1', [1, 2, 3], false, OrderSyncTrigger::Manual, null), $outcomes);
    }

    private static function posted(int $reference): OrderSyncOutcomeDTO
    {
        return OrderSyncOutcomeDTO::found(self::claimed($reference), new ExistingDocumentDTO("A{$reference}", "qbo-{$reference}", DocType::Invoice));
    }

    private static function failed(int $reference): OrderSyncOutcomeDTO
    {
        return OrderSyncOutcomeDTO::failed(self::claimed($reference), SyncOutcomeResolver::forUnknown(), DocType::Invoice);
    }

    private static function released(int $reference): OrderSyncOutcomeDTO
    {
        return OrderSyncOutcomeDTO::released(self::claimed($reference));
    }

    private static function claimed(int $reference): ClaimedOrderDTO
    {
        return new ClaimedOrderDTO(
            new SourceOrderRowDTO(
                reference: $reference,
                externalId: $reference,
                createdAt: new DateTimeImmutable('2026-11-01 10:00:00+00'),
                status: OrderLifecycleStatus::Processing,
                paymentMethod: 'card',
                billing: SyncOrderFixture::contact(),
                billingEmail: 'buyer@example.test',
                billingPhone: '',
                delivery: SyncOrderFixture::contact(),
                shippingMethod: 'Next Day',
                shippingTotalNet: '0',
                shippingVatRate: null,
                comments: '',
                transactionId: '',
                lines: [],
            ),
            new OrderSyncClaimDTO("sync-{$reference}", "token-{$reference}", 1, new DateTimeImmutable('2026-11-01 10:05:00+00'), null),
            false,
        );
    }
}
