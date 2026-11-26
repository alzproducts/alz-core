<?php

declare(strict_types=1);

namespace Tests\Unit\Application\QuickBooks\OrderSync\Services;

use App\Application\Contracts\QuickBooks\QuickBooksOrderPostingInterface;
use App\Application\QuickBooks\OrderSync\DTOs\ClaimedOrderDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncClaimDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncOutcomeDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncPostedDTO;
use App\Application\QuickBooks\OrderSync\DTOs\PostableDocumentDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ReadyOrderDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SourceOrderRowDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SyncFailureDTO;
use App\Application\QuickBooks\OrderSync\Enums\SyncOutcome;
use App\Application\QuickBooks\OrderSync\Results\BatchItemOutcomeResult;
use App\Application\QuickBooks\OrderSync\Services\OrderSyncPostingService;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Catalog\Order\ValueObjects\OrderLifecycleStatus;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\QuickBooks\Exceptions\QuickBooksDeadlineExceededException;
use App\Domain\Shared\ValueObjects\Deadline;
use DateTimeImmutable;
use Error;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\Unit\Application\QuickBooks\OrderSync\DocumentDraftFixture;
use Tests\Unit\Application\QuickBooks\OrderSync\SyncOrderFixture;
use Throwable;
use TypeError;

#[CoversClass(OrderSyncPostingService::class)]
final class OrderSyncPostingServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MockInterface&QuickBooksOrderPostingInterface $posting;

    private MockInterface&LoggerInterface $logger;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->posting = Mockery::mock(QuickBooksOrderPostingInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
    }

    #[Test]
    public function no_ready_order_makes_no_call(): void
    {
        $this->posting->shouldNotReceive('createDocuments');

        self::assertSame([], $this->post([]));
    }

    #[Test]
    public function each_order_takes_its_own_result(): void
    {
        $this->posting->shouldReceive('createDocuments')->once()
            ->andReturn([BatchItemOutcomeResult::created('txn-1'), BatchItemOutcomeResult::ambiguous()]);

        $outcomes = $this->post([self::ready(1), self::ready(2)]);

        self::assertInstanceOf(OrderSyncPostedDTO::class, $outcomes[0]->detail);
        self::assertSame('txn-1', $outcomes[0]->detail->qboTxnId);
        self::assertSame(['synced', 'outcome_unknown'], self::summary($outcomes));
    }

    /**
     * @return iterable<string, array{Throwable, string}>
     */
    public static function refusals(): iterable
    {
        yield 'deadline' => [new QuickBooksDeadlineExceededException(0.5), 'deadline_exceeded'];
        yield 'unavailable' => [new ExternalServiceUnavailableException('QuickBooks'), 'qbo_unavailable'];
    }

    #[Test]
    #[DataProvider('refusals')]
    public function a_call_that_sent_nothing_releases_its_orders(Throwable $throw, string $code): void
    {
        $this->posting->shouldReceive('createDocuments')->once()->andThrow($throw);

        $outcomes = $this->post([self::ready(1), self::ready(2)]);

        self::assertSame([$code, $code], self::summary($outcomes));
        self::assertSame([SyncOutcome::NotAttempted, SyncOutcome::NotAttempted], self::outcomeKinds($outcomes));
    }

    #[Test]
    public function a_batch_still_rate_limited_releases_its_orders(): void
    {
        $this->posting->shouldReceive('createDocuments')->once()
            ->andReturn([BatchItemOutcomeResult::rateLimited(30), BatchItemOutcomeResult::rateLimited(30)]);

        $outcomes = $this->post([self::ready(1), self::ready(2)]);

        self::assertSame(['qbo_unavailable', 'qbo_unavailable'], self::summary($outcomes));
        self::assertSame([SyncOutcome::NotAttempted, SyncOutcome::NotAttempted], self::outcomeKinds($outcomes));
    }

    #[Test]
    public function a_rate_limited_item_beside_a_created_one_fails(): void
    {
        $this->posting->shouldReceive('createDocuments')->once()
            ->andReturn([BatchItemOutcomeResult::created('txn-1'), BatchItemOutcomeResult::rateLimited(30)]);

        $outcomes = $this->post([self::ready(1), self::ready(2)]);

        self::assertSame(['synced', 'qbo_unavailable'], self::summary($outcomes));
        self::assertSame([SyncOutcome::Synced, SyncOutcome::Failed], self::outcomeKinds($outcomes));
    }

    #[Test]
    public function a_rejected_token_passes_through(): void
    {
        $this->posting->shouldReceive('createDocuments')->once()->andThrow(new AuthenticationExpiredException('QuickBooks'));

        $this->expectException(AuthenticationExpiredException::class);

        $this->post([self::ready(1)]);
    }

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function undocumentedThrows(): iterable
    {
        yield 'runtime exception' => [new RuntimeException('customer data')];
        yield 'error' => [new Error('customer data')];
    }

    #[Test]
    #[DataProvider('undocumentedThrows')]
    public function any_other_throw_is_an_unknown_outcome_logged_by_class(Throwable $throw): void
    {
        $this->posting->shouldReceive('createDocuments')->once()->andThrow($throw);
        $this->expectUnknownLogged($throw::class);

        self::assertSame(['outcome_unknown', 'outcome_unknown'], self::summary($this->post([self::ready(1), self::ready(2)])));
    }

    #[Test]
    public function results_that_do_not_match_the_orders_are_an_unknown_outcome(): void
    {
        $this->posting->shouldReceive('createDocuments')->once()->andReturn([BatchItemOutcomeResult::created('txn-1')]);
        $this->expectUnknownLogged(TypeError::class);

        self::assertSame(['outcome_unknown', 'outcome_unknown'], self::summary($this->post([self::ready(1), self::ready(2)])));
    }

    /**
     * @param class-string<Throwable> $class
     */
    private function expectUnknownLogged(string $class): void
    {
        $this->logger->shouldReceive('error')->once()
            ->with('QuickBooks batch create outcome is unknown', ['references' => [1, 2], 'exception' => $class]);
    }

    /**
     * @param list<ReadyOrderDTO> $ready
     *
     * @return list<OrderSyncOutcomeDTO>
     */
    private function post(array $ready): array
    {
        $deadline = Deadline::at(new DateTimeImmutable('2026-11-01 10:00:20+00'));

        return new OrderSyncPostingService($this->posting, $this->logger)->post(new ApiKeyToken('test-access-token'), $ready, $deadline);
    }

    /**
     * @param list<OrderSyncOutcomeDTO> $outcomes
     *
     * @return list<string>
     */
    private static function summary(array $outcomes): array
    {
        return \array_map(
            static fn(OrderSyncOutcomeDTO $outcome): string
                => $outcome->detail instanceof SyncFailureDTO ? $outcome->detail->code->value : $outcome->outcome->value,
            $outcomes,
        );
    }

    /**
     * @param list<OrderSyncOutcomeDTO> $outcomes
     *
     * @return list<SyncOutcome>
     */
    private static function outcomeKinds(array $outcomes): array
    {
        return \array_map(static fn(OrderSyncOutcomeDTO $outcome): SyncOutcome => $outcome->outcome, $outcomes);
    }

    private static function ready(int $reference): ReadyOrderDTO
    {
        return new ReadyOrderDTO(self::claimed($reference), new PostableDocumentDTO(DocumentDraftFixture::invoice(), DocumentDraftFixture::refs()));
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
