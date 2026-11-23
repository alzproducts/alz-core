<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\QuickBooks;

use App\Application\QuickBooks\OrderSync\Enums\BatchOutcomeKind;
use App\Application\QuickBooks\OrderSync\Results\BatchItemOutcomeResult;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\QuickBooks\Enums\QuickBooksFaultKind;
use App\Domain\QuickBooks\Exceptions\QuickBooksDeadlineExceededException;
use App\Domain\Shared\ValueObjects\Deadline;
use App\Infrastructure\QuickBooks\QuickBooksBatchExecution;
use App\Infrastructure\QuickBooks\QuickBooksCurlHttpClient;
use App\Infrastructure\QuickBooks\QuickBooksDataServiceFactory;
use App\Infrastructure\QuickBooks\QuickBooksDataServiceSession;
use App\Infrastructure\QuickBooks\QuickBooksFaultMapper;
use App\Infrastructure\QuickBooks\QuickBooksRateLimiter;
use App\Infrastructure\QuickBooks\QuickBooksTransport;
use App\Infrastructure\QuickBooks\QuickBooksXmlParsing;
use App\Infrastructure\Support\TransientLogThrottle;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use QuickBooksOnline\API\Core\HttpClients\FaultHandler;
use QuickBooksOnline\API\Core\HttpClients\IntuitResponse;
use QuickBooksOnline\API\Data\IPPInvoice;
use QuickBooksOnline\API\Data\IPPSalesReceipt;
use QuickBooksOnline\API\DataService\Batch;
use QuickBooksOnline\API\DataService\DataService;
use QuickBooksOnline\API\DataService\IntuitBatchResponse;
use QuickBooksOnline\API\Exception\IdsException;
use QuickBooksOnline\API\Exception\SdkException;
use QuickBooksOnline\API\Exception\SecurityException;
use QuickBooksOnline\API\Exception\ServiceException;
use QuickBooksOnline\API\Exception\ValidationException;
use Throwable;
use TypeError;

#[CoversClass(QuickBooksTransport::class)]
#[CoversClass(QuickBooksBatchExecution::class)]
#[CoversClass(QuickBooksFaultMapper::class)]
final class QuickBooksTransportBatchTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var list<float> */
    private array $slept = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-22 10:00:00');
        Sleep::fake(syncWithCarbon: true);
        Sleep::whenFakingSleep(function (CarbonInterval $duration): void {
            $this->slept[] = $duration->totalSeconds;
        });
    }

    #[Override]
    protected function tearDown(): void
    {
        Sleep::fake(false);
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function it_returns_one_outcome_per_item_and_a_fault_leaves_its_siblings_created(): void
    {
        $execution = $this->transport([$this->batchSession(responses: [
            '1' => self::created(new IPPInvoice(), '101'),
            '2' => self::failed(new ValidationException('Duplicate Document Number Error - Detail', 6140)),
            '3' => self::created(new IPPSalesReceipt(), '103'),
        ])])->executeBatch(self::token(), self::entities(), self::deadline());

        $first = $execution->outcomeFor('1', IPPInvoice::class);
        $second = $execution->outcomeFor('2', IPPInvoice::class);
        $third = $execution->outcomeFor('3', IPPSalesReceipt::class);

        $this->assertSame([BatchOutcomeKind::Created, '101'], [$first->kind, $first->qboId]);
        $this->assertSame(BatchOutcomeKind::Faulted, $second->kind);
        $this->assertSame(QuickBooksFaultKind::DuplicateDocNumber, $second->fault?->kind);
        $this->assertSame('6140', $second->fault->code);
        $this->assertSame('Duplicate Document Number Error - Detail', $second->fault->message);
        $this->assertSame([BatchOutcomeKind::Created, '103'], [$third->kind, $third->qboId]);
    }

    #[Test]
    public function it_sends_each_entity_under_its_index_plus_one(): void
    {
        $entities = self::entities();
        $batch = Mockery::mock(Batch::class);
        foreach ($entities as $index => $entity) {
            $batch->shouldReceive('AddEntity')->once()->with($entity, (string) ($index + 1), 'create')->ordered();
        }
        $batch->shouldReceive('Execute')->once()->ordered();
        $batch->shouldReceive('getLastError')->andReturn(false);
        $batch->shouldReceive('ReadOnlyCollection')->andReturn([]);

        $this->transport([self::sessionWith($batch)])->executeBatch(self::token(), $entities, self::deadline());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function ambiguousItemResponses(): iterable
    {
        yield 'missing' => [null];
        yield 'not a batch response' => [new IPPInvoice()];
        yield 'service fault' => [self::failed(new ServiceException('Internal error', 500))];
        yield 'security fault' => [self::failed(new SecurityException('Unauthorized', 100))];
        yield 'fault of unknown type' => [self::failed(new IdsException('Fault Exception of type unknown'))];
        yield 'fault the SDK could not read' => [self::failed(null)];
        yield 'success of another entity type' => [self::created(new IPPSalesReceipt(), '101')];
        yield 'success without an id' => [self::created(new IPPInvoice(), null)];
    }

    #[Test]
    #[DataProvider('ambiguousItemResponses')]
    public function an_unreadable_item_response_is_ambiguous_for_that_item_only(mixed $response): void
    {
        $responses = ['2' => self::created(new IPPInvoice(), '102')];
        if ($response !== null) {
            $responses['1'] = $response;
        }

        $execution = $this->transport([$this->batchSession(responses: $responses)])->executeBatch(self::token(), self::entities(), self::deadline());

        $this->assertSame(BatchOutcomeKind::Ambiguous, $execution->outcomeFor('1', IPPInvoice::class)->kind);
        $this->assertSame(BatchOutcomeKind::Created, $execution->outcomeFor('2', IPPInvoice::class)->kind);
    }

    #[Test]
    public function a_validation_fault_without_a_code_is_a_plain_validation_fault(): void
    {
        $outcome = QuickBooksFaultMapper::batchItem(self::failed(new ValidationException('Rejected')), IPPInvoice::class);

        $this->assertSame(BatchOutcomeKind::Faulted, $outcome->kind);
        $this->assertSame(QuickBooksFaultKind::Validation, $outcome->fault?->kind);
        $this->assertNull($outcome->fault->code);
    }

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function sendFailures(): iterable
    {
        yield 'transport error' => [new SdkException('curl failed')];
        yield 'type error on an odd fault code' => [new TypeError('code must be int')];
    }

    #[Test]
    #[DataProvider('sendFailures')]
    public function a_throw_from_execute_makes_every_item_ambiguous(Throwable $failure): void
    {
        $execution = $this->transport([$this->batchSession(throws: $failure)])->executeBatch(self::token(), self::entities(), self::deadline());

        $this->assertSame(BatchOutcomeKind::Ambiguous, $execution->outcomeFor('1', IPPInvoice::class)->kind);
        $this->assertSame(BatchOutcomeKind::Ambiguous, $execution->outcomeFor('3', IPPSalesReceipt::class)->kind);
    }

    #[Test]
    public function a_call_level_5xx_makes_every_item_ambiguous(): void
    {
        $execution = $this->transport([$this->batchSession(lastError: self::fault(503, ''))])->executeBatch(self::token(), self::entities(), self::deadline());

        $this->assertSame(BatchOutcomeKind::Ambiguous, $execution->outcomeFor('1', IPPInvoice::class)->kind);
        $this->assertSame(BatchOutcomeKind::Ambiguous, $execution->outcomeFor('2', IPPInvoice::class)->kind);
    }

    #[Test]
    public function a_call_level_400_maps_every_item_by_its_code(): void
    {
        $execution = $this->transport([$this->batchSession(lastError: self::fault(400, self::faultXml('6210')))])->executeBatch(self::token(), self::entities(), self::deadline());

        foreach (['1', '2', '3'] as $batchItemId) {
            $outcome = $execution->outcomeFor($batchItemId, IPPInvoice::class);
            $this->assertSame(BatchOutcomeKind::Faulted, $outcome->kind);
            $this->assertSame(QuickBooksFaultKind::ClosedPeriod, $outcome->fault?->kind);
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function authStatuses(): iterable
    {
        yield '401' => [401];
        yield '403' => [403];
    }

    #[Test]
    #[DataProvider('authStatuses')]
    public function a_call_level_401_or_403_throws(int $status): void
    {
        $this->expectException(AuthenticationExpiredException::class);

        $this->transport([$this->batchSession(lastError: self::fault($status, ''))])->executeBatch(self::token(), self::entities(), self::deadline());
    }

    #[Test]
    public function a_429_then_a_successful_retry_on_a_fresh_session_creates_every_item(): void
    {
        $responses = [
            '1' => self::created(new IPPInvoice(), '101'),
            '2' => self::created(new IPPInvoice(), '102'),
            '3' => self::created(new IPPSalesReceipt(), '103'),
        ];

        $execution = $this->transport([
            $this->batchSession(lastError: self::fault(429, ''), retryAfter: 3),
            $this->batchSession(responses: $responses),
        ])->executeBatch(self::token(), self::entities(), self::deadline(60));

        $this->assertSame(['101', '102', '103'], [
            $execution->outcomeFor('1', IPPInvoice::class)->qboId,
            $execution->outcomeFor('2', IPPInvoice::class)->qboId,
            $execution->outcomeFor('3', IPPSalesReceipt::class)->qboId,
        ]);
        $this->assertCount(1, $this->slept);
        $this->assertGreaterThanOrEqual(3.0, $this->slept[0]);
    }

    #[Test]
    public function a_429_past_the_retry_window_makes_every_item_rate_limited(): void
    {
        $execution = $this->transport([$this->batchSession(lastError: self::fault(429, ''), retryAfter: 120)])->executeBatch(self::token(), self::entities(), self::deadline(60));

        $outcome = $execution->outcomeFor('2', IPPInvoice::class);
        $this->assertSame([BatchOutcomeKind::RateLimited, 120], [$outcome->kind, $outcome->retryAfterSeconds]);
        $this->assertSame([], $this->slept);
    }

    #[Test]
    public function a_429_retry_the_limiter_refuses_makes_every_item_rate_limited(): void
    {
        $limiter = Mockery::mock(QuickBooksRateLimiter::class);
        $limiter->shouldReceive('acquireBy')->once()->ordered();
        $limiter->shouldReceive('acquireBy')->once()->ordered()->andThrow(new ExternalServiceUnavailableException('QuickBooks'));

        $execution = $this->transport([$this->batchSession(lastError: self::fault(429, ''), retryAfter: 2)], $limiter)
            ->executeBatch(self::token(), self::entities(), self::deadline(60));

        $this->assertSame([BatchOutcomeKind::RateLimited, 2], [$execution->outcomeFor('1', IPPInvoice::class)->kind, $execution->outcomeFor('1', IPPInvoice::class)->retryAfterSeconds]);
    }

    #[Test]
    public function a_limiter_outage_before_the_first_send_throws_and_sends_nothing(): void
    {
        $limiter = Mockery::mock(QuickBooksRateLimiter::class);
        $limiter->shouldReceive('acquireBy')->once()->andThrow(new ExternalServiceUnavailableException('QuickBooks'));

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->transport([], $limiter)->executeBatch(self::token(), self::entities(), self::deadline());
    }

    #[Test]
    public function a_call_that_cannot_start_in_time_throws_and_sends_nothing(): void
    {
        $this->expectException(QuickBooksDeadlineExceededException::class);

        $this->transport([])->executeBatch(self::token(), self::entities(), self::deadline(QuickBooksTransport::START_FLOOR_SECONDS - 1));
    }

    #[Test]
    public function a_second_call_reuses_nothing_from_the_first(): void
    {
        $transport = $this->transport([
            $this->batchSession(responses: ['1' => self::created(new IPPInvoice(), '101')]),
            $this->batchSession(responses: ['1' => self::failed(new ValidationException('Rejected', 2500))]),
        ]);

        $first = $transport->executeBatch(self::token(), [new IPPInvoice()], self::deadline());
        $second = $transport->executeBatch(self::token(), [new IPPInvoice()], self::deadline());

        $this->assertSame(BatchOutcomeKind::Created, $first->outcomeFor('1', IPPInvoice::class)->kind);
        $this->assertSame(QuickBooksFaultKind::InvalidReference, $second->outcomeFor('1', IPPInvoice::class)->fault?->kind);
    }

    #[Test]
    public function a_shared_outcome_answers_for_every_item(): void
    {
        $outcome = BatchItemOutcomeResult::ambiguous();

        $this->assertSame($outcome, QuickBooksBatchExecution::everyItem($outcome)->outcomeFor('7', IPPInvoice::class));
    }

    /**
     * @return iterable<string, array{int, string, ?string}>
     */
    public static function loggedFailures(): iterable
    {
        foreach (['with the header' => 'tid-1', 'without it' => null] as $case => $intuitTid) {
            yield "400 fault {$case}" => [400, self::faultXml('6210'), $intuitTid];
            yield "429 {$case}" => [429, '', $intuitTid];
            yield "503 {$case}" => [503, '', $intuitTid];
        }
    }

    #[Test]
    #[DataProvider('loggedFailures')]
    public function a_failed_batch_call_is_logged_with_its_intuit_tid_and_carries_it(int $status, string $body, ?string $intuitTid): void
    {
        $logger = new RecordingLogger();
        $session = $this->batchSession(lastError: self::fault($status, $body), retryAfter: 120, intuitTid: $intuitTid);

        $execution = $this->transport([$session], logger: $logger)->executeBatch(self::token(), self::entities(), self::deadline());

        $this->assertSame($intuitTid, $execution->intuitTid);
        $this->assertCount(1, $logger->records);
        $this->assertSame($status, $logger->records[0][1]['status']);
        $this->assertArrayHasKey('intuit_tid', $logger->records[0][1]);
        $this->assertSame($intuitTid, $logger->records[0][1]['intuit_tid']);
    }

    #[Test]
    public function a_401_batch_call_is_logged_with_its_intuit_tid(): void
    {
        $logger = new RecordingLogger();

        try {
            $this->transport([$this->batchSession(lastError: self::fault(401, ''), intuitTid: 'tid-1')], logger: $logger)
                ->executeBatch(self::token(), self::entities(), self::deadline());
            $this->fail('Expected AuthenticationExpiredException');
        } catch (AuthenticationExpiredException) {
            $this->assertSame('tid-1', $logger->records[0][1]['intuit_tid']);
        }
    }

    #[Test]
    public function a_batch_send_that_threw_is_logged_with_the_intuit_tid_of_any_response_it_got(): void
    {
        foreach (['tid-1', null] as $intuitTid) {
            $logger = new RecordingLogger();
            $session = $this->batchSession(throws: new TypeError('code must be int'), intuitTid: $intuitTid);

            $execution = $this->transport([$session], logger: $logger)->executeBatch(self::token(), self::entities(), self::deadline());

            $this->assertSame($intuitTid, $execution->intuitTid);
            $this->assertSame(['status' => null, 'exception' => TypeError::class, 'intuit_tid' => $intuitTid], $logger->records[0][1]);
        }
    }

    #[Test]
    public function an_answered_batch_carries_the_intuit_tid_of_the_response_that_answered_it(): void
    {
        $execution = $this->transport([
            $this->batchSession(lastError: self::fault(429, ''), retryAfter: 3, intuitTid: 'tid-429'),
            $this->batchSession(responses: ['1' => self::failed(new ValidationException('Rejected', 2500))], intuitTid: 'tid-200'),
        ])->executeBatch(self::token(), self::entities(), self::deadline(60));

        $this->assertSame('tid-200', $execution->intuitTid);
    }

    /**
     * @return list<IPPInvoice|IPPSalesReceipt>
     */
    private static function entities(): array
    {
        return [new IPPInvoice(), new IPPInvoice(), new IPPSalesReceipt()];
    }

    private static function created(IPPInvoice|IPPSalesReceipt $entity, ?string $id): IntuitBatchResponse
    {
        $entity->Id = $id;
        $response = new IntuitBatchResponse();
        $response->entity = $entity;
        $response->successFlagOn();

        return $response;
    }

    private static function failed(?IdsException $exception): IntuitBatchResponse
    {
        $response = new IntuitBatchResponse();
        $response->exception = $exception;

        return $response;
    }

    private static function token(): ApiKeyToken
    {
        return new ApiKeyToken('access');
    }

    private static function deadline(int $seconds = 30): Deadline
    {
        return Deadline::after(CarbonImmutable::now(), $seconds);
    }

    /**
     * Built the way the curl client builds it, so the SDK's own content-type parsing runs.
     */
    private static function fault(int $status, string $body): FaultHandler
    {
        $fault = QuickBooksXmlParsing::quietly(
            static fn(): mixed => new IntuitResponse("HTTP/1.1 {$status}\r\nContent-Type: application/xml\r\n", $body, $status, true)->getFaultHandler(),
        );
        self::assertInstanceOf(FaultHandler::class, $fault);

        return $fault;
    }

    private static function faultXml(string $code): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <IntuitResponse xmlns="http://schema.intuit.com/finance/v3">
              <Fault type="ValidationFault">
                <Error code="{$code}" element=""><Message>Rejected</Message><Detail></Detail></Error>
              </Fault>
            </IntuitResponse>
            XML;
    }

    /**
     * @param array<string, mixed> $responses
     */
    private function batchSession(
        array $responses = [],
        FaultHandler|false $lastError = false,
        ?Throwable $throws = null,
        ?int $retryAfter = null,
        ?string $intuitTid = null,
    ): QuickBooksDataServiceSession {
        $batch = Mockery::mock(Batch::class);
        $batch->shouldReceive('AddEntity');
        $execute = $batch->shouldReceive('Execute')->once();
        if ($throws !== null) {
            $execute->andThrow($throws);
        }
        $batch->shouldReceive('getLastError')->andReturn($lastError);
        $batch->shouldReceive('ReadOnlyCollection')->andReturn($responses);

        $httpClient = Mockery::mock(QuickBooksCurlHttpClient::class);
        $httpClient->shouldReceive('retryAfterSeconds')->andReturn($retryAfter);
        $httpClient->shouldReceive('intuitTid')->andReturn($intuitTid);

        return self::sessionWith($batch, $httpClient);
    }

    private static function sessionWith(Batch $batch, ?QuickBooksCurlHttpClient $httpClient = null): QuickBooksDataServiceSession
    {
        $dataService = Mockery::mock(DataService::class);
        $dataService->shouldReceive('CreateNewBatch')->once()->andReturn($batch);

        return new QuickBooksDataServiceSession($dataService, $httpClient ?? new QuickBooksCurlHttpClient());
    }

    /**
     * @param list<QuickBooksDataServiceSession> $sessions one per expected attempt, in order
     */
    private function transport(array $sessions, ?QuickBooksRateLimiter $limiter = null, ?AbstractLogger $logger = null): QuickBooksTransport
    {
        $factory = Mockery::mock(QuickBooksDataServiceFactory::class);
        if ($sessions === []) {
            $factory->shouldNotReceive('create');
        } else {
            $factory->shouldReceive('create')->times(\count($sessions))->andReturn(...$sessions);
        }

        if ($limiter === null) {
            $limiter = Mockery::mock(QuickBooksRateLimiter::class);
            $limiter->shouldReceive('acquire', 'acquireBy');
        }

        $logger ??= new NullLogger();
        $throttle = Mockery::mock(TransientLogThrottle::class);
        $throttle->shouldReceive('logTransient')->andReturnUsing(
            static function (string $service, string $message, array $context) use ($logger): void {
                $logger->warning($message, $context);
            },
        );

        return new QuickBooksTransport($factory, $limiter, $throttle, $logger);
    }
}
