<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\QuickBooks;

use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\QuickBooks\Enums\QuickBooksFaultKind;
use App\Domain\QuickBooks\Exceptions\QuickBooksDeadlineExceededException;
use App\Domain\QuickBooks\Exceptions\QuickBooksFaultException;
use App\Domain\Shared\ValueObjects\Deadline;
use App\Infrastructure\QuickBooks\QuickBooksCurlHttpClient;
use App\Infrastructure\QuickBooks\QuickBooksDataServiceFactory;
use App\Infrastructure\QuickBooks\QuickBooksDataServiceSession;
use App\Infrastructure\QuickBooks\QuickBooksRateLimiter;
use App\Infrastructure\QuickBooks\QuickBooksTransport;
use App\Infrastructure\QuickBooks\QuickBooksXmlParsing;
use App\Infrastructure\Support\TransientLogThrottle;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use QuickBooksOnline\API\Core\HttpClients\FaultHandler;
use QuickBooksOnline\API\Core\HttpClients\IntuitResponse;
use QuickBooksOnline\API\Data\IPPCompanyInfo;
use QuickBooksOnline\API\Data\IPPCustomer;
use QuickBooksOnline\API\DataService\DataService;
use QuickBooksOnline\API\Exception\IdsException;
use QuickBooksOnline\API\Exception\SdkException;
use Throwable;

#[CoversClass(QuickBooksTransport::class)]
final class QuickBooksTransportTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const string SENTINEL = 'Sentinel Customer Ltd';

    private const string QUERY = "SELECT * FROM Customer WHERE DisplayName IN ('" . self::SENTINEL . "')";

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
    public function it_returns_the_sdk_result(): void
    {
        $companyInfo = new IPPCompanyInfo();

        $this->assertSame($companyInfo, $this->transport([$this->session($companyInfo, false)])->companyInfo(self::token()));
    }

    /**
     * @param class-string<Throwable> $expected
     */
    #[Test]
    #[DataProvider('callFaults')]
    public function a_returned_fault_raises_its_mapped_error(int $status, string $body, string $expected): void
    {
        $this->expectException($expected);

        $this->transport([$this->session(null, self::fault($status, $body))])->companyInfo(self::token());
    }

    /**
     * @return array<string, array{int, string, class-string<Throwable>}>
     */
    public static function callFaults(): array
    {
        return [
            '401' => [401, '', AuthenticationExpiredException::class],
            '403' => [403, '', AuthenticationExpiredException::class],
            '400 fault' => [400, self::faultXml('6240', 'Name'), QuickBooksFaultException::class],
            '500' => [500, '', ExternalServiceUnavailableException::class],
        ];
    }

    #[Test]
    public function a_400_fault_keeps_its_kind_code_and_element(): void
    {
        try {
            $this->transport([$this->session(null, self::fault(400, self::faultXml('6240', 'Name')))])->companyInfo(self::token());
            $this->fail('Expected QuickBooksFaultException');
        } catch (QuickBooksFaultException $e) {
            $this->assertSame(QuickBooksFaultKind::DuplicateCustomerName, $e->fault->kind);
            $this->assertSame('6240', $e->context()['code']);
            $this->assertSame('Name', $e->context()['element']);
        }
    }

    /**
     * @param Throwable $thrown
     */
    #[Test]
    #[DataProvider('thrownErrors')]
    public function a_thrown_sdk_error_is_unavailable_with_nothing_chained(Throwable $thrown): void
    {
        $dataService = Mockery::mock(DataService::class);
        $dataService->shouldReceive('getCompanyInfo')->once()->andThrow($thrown);

        try {
            $this->transport([new QuickBooksDataServiceSession($dataService, new QuickBooksCurlHttpClient())])->companyInfo(self::token());
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertNull($e->getPrevious());
        }
    }

    /**
     * @return array<string, array{Throwable}>
     */
    public static function thrownErrors(): array
    {
        return [
            'curl error' => [new SdkException('cURL error during making API call. cURL Error Number:[28]')],
            'ids error' => [new IdsException('boom')],
        ];
    }

    #[Test]
    public function a_customer_name_in_a_fault_body_reaches_no_log_context_or_previous(): void
    {
        $logger = new RecordingLogger();
        $body = self::faultXml('6240', 'Name', 'Duplicate Name Exists Error', 'The name supplied already exists: ' . self::SENTINEL);

        try {
            $this->transport([$this->session(null, self::fault(400, $body))], logger: $logger)->companyInfo(self::token());
            $this->fail('Expected QuickBooksFaultException');
        } catch (QuickBooksFaultException $e) {
            $this->assertStringNotContainsString(self::SENTINEL, $e->getMessage());
            $this->assertStringNotContainsString(self::SENTINEL, \json_encode($e->context(), \JSON_THROW_ON_ERROR));
            $this->assertNull($e->getPrevious());
        }

        $this->assertNotSame([], $logger->records);
        $this->assertStringNotContainsString(self::SENTINEL, \json_encode($logger->records, \JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function a_call_with_under_a_client_timeout_left_is_refused_before_the_limiter_and_the_sdk(): void
    {
        $limiter = Mockery::mock(QuickBooksRateLimiter::class);
        $limiter->shouldNotReceive('acquire', 'acquireBy');

        try {
            $this->transport([], $limiter)->query(self::token(), self::QUERY, self::deadline(QuickBooksTransport::START_FLOOR_SECONDS - 1));
            $this->fail('Expected QuickBooksDeadlineExceededException');
        } catch (QuickBooksDeadlineExceededException $e) {
            $this->assertEqualsWithDelta(-1.0, $e->secondsLeft, 0.001);
        }
    }

    #[Test]
    public function the_limiter_may_start_the_call_no_later_than_one_client_timeout_before_the_deadline(): void
    {
        $limiter = Mockery::mock(QuickBooksRateLimiter::class);
        $limiter->shouldReceive('acquireBy')->once()->withArgs(
            static fn(bool $batchCall, Deadline $startBy): bool => !$batchCall
                && $startBy->secondsLeft(CarbonImmutable::now()) === (float) (30 - QuickBooksTransport::START_FLOOR_SECONDS),
        );

        $this->transport([$this->querySession([], false)], $limiter)->query(self::token(), self::QUERY, self::deadline());
    }

    #[Test]
    public function a_company_info_read_takes_a_grant_with_no_start_by_time(): void
    {
        $limiter = Mockery::mock(QuickBooksRateLimiter::class);
        $limiter->shouldReceive('acquire')->once()->with(false);
        $companyInfo = new IPPCompanyInfo();

        $this->assertSame($companyInfo, $this->transport([$this->session($companyInfo, false)], $limiter)->companyInfo(self::token()));
    }

    #[Test]
    public function a_company_info_429_is_unavailable_without_a_retry(): void
    {
        $limiter = Mockery::mock(QuickBooksRateLimiter::class);
        $limiter->shouldReceive('acquire')->once();

        try {
            $this->transport([$this->session(null, self::fault(429, ''), self::retryAfter(7))], $limiter)->companyInfo(self::token());
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame(7, $e->retryAfter);
            $this->assertSame([], $this->slept);
        }
    }

    /**
     * @param Throwable $refusal
     */
    #[Test]
    #[DataProvider('firstGrantRefusals')]
    public function a_refused_first_grant_sends_nothing(Throwable $refusal): void
    {
        $limiter = Mockery::mock(QuickBooksRateLimiter::class);
        $limiter->shouldReceive('acquireBy')->once()->andThrow($refusal);

        $this->expectExceptionObject($refusal);

        $this->transport([], $limiter)->query(self::token(), self::QUERY, self::deadline());
    }

    /**
     * @return array<string, array{Throwable}>
     */
    public static function firstGrantRefusals(): array
    {
        return [
            'wait past the deadline' => [new QuickBooksDeadlineExceededException(-2.0)],
            'redis outage' => [new ExternalServiceUnavailableException('QuickBooks')],
        ];
    }

    #[Test]
    public function each_429_retry_takes_a_new_grant_and_a_new_data_service(): void
    {
        $limiter = Mockery::mock(QuickBooksRateLimiter::class);
        $limiter->shouldReceive('acquireBy')->twice();
        $rows = [new IPPCustomer()];

        $result = $this->transport([$this->rateLimited(null), $this->querySession($rows, false)], $limiter)
            ->query(self::token(), self::QUERY, self::deadline());

        $this->assertSame($rows, $result);
        $this->assertCount(1, $this->slept);
        $this->assertGreaterThanOrEqual(1.0, $this->slept[0]);
        $this->assertLessThanOrEqual(2.0, $this->slept[0]);
    }

    #[Test]
    public function back_off_doubles_with_jitter_up_to_about_a_minute(): void
    {
        $sessions = \array_map(fn(): QuickBooksDataServiceSession => $this->rateLimited(null), \range(1, 8));
        $sessions[] = $this->querySession([], false);

        $this->transport($sessions)->query(self::token(), self::QUERY, self::deadline(1_000));

        $steps = [2, 4, 8, 16, 32, 60, 60, 60];
        $this->assertCount(\count($steps), $this->slept);
        foreach ($steps as $i => $step) {
            $this->assertGreaterThanOrEqual($step / 2, $this->slept[$i], "retry {$i}");
            $this->assertLessThanOrEqual($step, $this->slept[$i], "retry {$i}");
        }

        $this->assertNotSame(\array_slice($this->slept, 5, 3), \array_fill(0, 3, $this->slept[5]), 'jitter varies the capped waits');
    }

    #[Test]
    public function a_retry_after_longer_than_the_back_off_is_waited_in_full(): void
    {
        $this->transport([$this->rateLimited(45), $this->querySession([], false)])
            ->query(self::token(), self::QUERY, self::deadline(120));

        $this->assertSame([45.0], $this->slept);
    }

    #[Test]
    public function a_429_with_no_retry_time_left_is_unavailable_with_the_last_retry_after(): void
    {
        try {
            $this->transport([$this->rateLimited(10)])->query(self::token(), self::QUERY, self::deadline(20));
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame(10, $e->retryAfter);
            $this->assertSame([], $this->slept);
        }
    }

    #[Test]
    public function a_retry_runs_out_of_time_after_earlier_waits(): void
    {
        try {
            $this->transport([$this->rateLimited(20), $this->rateLimited(26)])->query(self::token(), self::QUERY, self::deadline(60));
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame(26, $e->retryAfter);
            $this->assertSame([20.0], $this->slept);
        }
    }

    /**
     * @param Throwable $refusal
     */
    #[Test]
    #[DataProvider('retryGrantRefusals')]
    public function a_retry_the_limiter_refuses_is_rate_limited_not_a_deadline_error(Throwable $refusal): void
    {
        $limiter = Mockery::mock(QuickBooksRateLimiter::class);
        $limiter->shouldReceive('acquireBy')->once()->ordered();
        $limiter->shouldReceive('acquireBy')->once()->ordered()->andThrow($refusal);

        try {
            $this->transport([$this->rateLimited(7)], $limiter)->query(self::token(), self::QUERY, self::deadline());
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertNotSame($refusal, $e);
            $this->assertSame(7, $e->retryAfter);
        }
    }

    /**
     * @return array<string, array{Throwable}>
     */
    public static function retryGrantRefusals(): array
    {
        return [
            'wait past the deadline' => [new QuickBooksDeadlineExceededException(-2.0)],
            'redis outage' => [new ExternalServiceUnavailableException('QuickBooks')],
        ];
    }

    #[Test]
    public function a_query_returns_its_rows(): void
    {
        $customer = new IPPCustomer();

        $this->assertSame([$customer], $this->transport([$this->querySession([$customer], false)])->query(self::token(), self::QUERY, self::deadline()));
    }

    #[Test]
    public function a_null_query_result_with_no_fault_is_zero_rows(): void
    {
        $this->assertSame([], $this->transport([$this->querySession(null, false)])->query(self::token(), self::QUERY, self::deadline()));
    }

    #[Test]
    public function a_null_query_result_with_a_fault_raises_the_fault_not_zero_rows(): void
    {
        $this->expectException(QuickBooksFaultException::class);

        $this->transport([$this->querySession(null, self::fault(400, self::faultXml('4001', '')))])->query(self::token(), self::QUERY, self::deadline());
    }

    #[Test]
    public function a_query_result_that_is_not_a_list_is_unexpected_and_logs_no_query_text(): void
    {
        $logger = new RecordingLogger();

        try {
            $this->transport([$this->querySession(new IPPCustomer(), false)], logger: $logger)->query(self::token(), self::QUERY, self::deadline());
            $this->fail('Expected UnexpectedApiResultException');
        } catch (UnexpectedApiResultException) {
            $this->assertStringNotContainsString(self::SENTINEL, \serialize($logger->records));
            $this->assertNotSame([], $logger->records);
        }
    }

    #[Test]
    public function an_add_returns_the_created_entity(): void
    {
        $created = new IPPCustomer();

        $this->assertSame($created, $this->transport([$this->addSession($created, false)])->add(self::token(), new IPPCustomer(), IPPCustomer::class, self::deadline()));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unusableAddResults(): array
    {
        return [
            'unparsed response returned as an exception' => [new Exception('parse failure')],
            'local refusal' => [new IdsException('refused')],
            'another entity type' => [new IPPCompanyInfo()],
            'null' => [null],
        ];
    }

    #[Test]
    #[DataProvider('unusableAddResults')]
    public function an_add_result_that_is_not_the_entity_sent_is_unexpected(mixed $result): void
    {
        $this->expectException(UnexpectedApiResultException::class);

        $this->transport([$this->addSession($result, false)])->add(self::token(), new IPPCustomer(), IPPCustomer::class, self::deadline());
    }

    /**
     * @param class-string<Throwable> $expected
     */
    #[Test]
    #[DataProvider('callFaults')]
    public function an_add_fault_raises_its_mapped_error(int $status, string $body, string $expected): void
    {
        $this->expectException($expected);

        $this->transport([$this->addSession(null, self::fault($status, $body))])->add(self::token(), new IPPCustomer(), IPPCustomer::class, self::deadline());
    }

    #[Test]
    public function an_add_transport_error_is_unavailable(): void
    {
        $this->expectException(ExternalServiceUnavailableException::class);

        $this->transport([$this->addSession(new SdkException('curl failed'), false, throws: true)])->add(self::token(), new IPPCustomer(), IPPCustomer::class, self::deadline());
    }

    /**
     * @return iterable<string, array{int, string, ?string}>
     */
    public static function loggedFailures(): iterable
    {
        foreach (['with the header' => 'tid-1', 'without it' => null] as $case => $intuitTid) {
            yield "401 {$case}" => [401, '', $intuitTid];
            yield "400 fault {$case}" => [400, self::faultXml('6240', 'Name'), $intuitTid];
            yield "429 {$case}" => [429, '', $intuitTid];
            yield "503 {$case}" => [503, '', $intuitTid];
        }
    }

    #[Test]
    #[DataProvider('loggedFailures')]
    public function a_failed_response_is_logged_with_its_intuit_tid(int $status, string $body, ?string $intuitTid): void
    {
        $fault = self::fault($status, $body);
        $httpClient = self::retryAfter(60, $intuitTid);

        foreach ([
            fn(RecordingLogger $logger): mixed => $this->transport([$this->session(null, $fault, $httpClient)], logger: $logger)->companyInfo(self::token()),
            fn(RecordingLogger $logger): mixed => $this->transport([$this->querySession(null, $fault, $httpClient)], logger: $logger)->query(self::token(), self::QUERY, self::deadline()),
            fn(RecordingLogger $logger): mixed => $this->transport([$this->addSession(null, $fault, httpClient: $httpClient)], logger: $logger)->add(self::token(), new IPPCustomer(), IPPCustomer::class, self::deadline()),
        ] as $call) {
            $this->assertLoggedOnce($call, $status, $intuitTid);
        }
    }

    #[Test]
    public function a_call_that_threw_is_logged_with_the_intuit_tid_of_any_response_it_got(): void
    {
        foreach (['tid-1', null] as $intuitTid) {
            $httpClient = self::retryAfter(null, $intuitTid);
            $thrown = new SdkException('failed');

            $this->assertLoggedOnce(
                fn(RecordingLogger $logger): mixed => $this->transport([$this->addSession($thrown, false, true, $httpClient)], logger: $logger)
                    ->add(self::token(), new IPPCustomer(), IPPCustomer::class, self::deadline()),
                null,
                $intuitTid,
            );
        }
    }

    /**
     * @param callable(RecordingLogger): mixed $call
     */
    private function assertLoggedOnce(callable $call, ?int $status, ?string $intuitTid): void
    {
        $logger = new RecordingLogger();

        try {
            $call($logger);
            $this->fail('Expected a translated failure');
        } catch (AuthenticationExpiredException|ExternalServiceUnavailableException|QuickBooksFaultException) {
        }

        $this->assertCount(1, $logger->records);
        $context = $logger->records[0][1];
        $this->assertSame($status, $context['status']);
        $this->assertArrayHasKey('intuit_tid', $context);
        $this->assertSame($intuitTid, $context['intuit_tid']);
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

    private static function faultXml(string $code, string $element, string $message = 'Rejected', string $detail = ''): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <IntuitResponse xmlns="http://schema.intuit.com/finance/v3">
              <Fault type="ValidationFault">
                <Error code="{$code}" element="{$element}"><Message>{$message}</Message><Detail>{$detail}</Detail></Error>
              </Fault>
            </IntuitResponse>
            XML;
    }

    private function querySession(mixed $result, FaultHandler|false $lastError, ?QuickBooksCurlHttpClient $httpClient = null): QuickBooksDataServiceSession
    {
        $dataService = Mockery::mock(DataService::class);
        $dataService->shouldReceive('Query')->once()->with(self::QUERY)->andReturn($result);
        $dataService->shouldReceive('getLastError')->andReturn($lastError);

        return new QuickBooksDataServiceSession($dataService, $httpClient ?? new QuickBooksCurlHttpClient());
    }

    /**
     * An IdsException result is thrown, as the SDK throws it for a local refusal; `throws` throws any other exception.
     */
    private function addSession(mixed $result, FaultHandler|false $lastError, bool $throws = false, ?QuickBooksCurlHttpClient $httpClient = null): QuickBooksDataServiceSession
    {
        $dataService = Mockery::mock(DataService::class);
        $expectation = $dataService->shouldReceive('Add')->once()->with(Mockery::type(IPPCustomer::class));
        if ($result instanceof Throwable && ($throws || $result instanceof IdsException)) {
            $expectation->andThrow($result);
        } else {
            $expectation->andReturn($result);
        }
        $dataService->shouldReceive('getLastError')->andReturn($lastError);

        return new QuickBooksDataServiceSession($dataService, $httpClient ?? new QuickBooksCurlHttpClient());
    }

    private function session(mixed $result, FaultHandler|false $lastError, ?QuickBooksCurlHttpClient $httpClient = null): QuickBooksDataServiceSession
    {
        $dataService = Mockery::mock(DataService::class);
        $dataService->shouldReceive('getCompanyInfo')->once()->andReturn($result);
        $dataService->shouldReceive('getLastError')->andReturn($lastError);

        return new QuickBooksDataServiceSession($dataService, $httpClient ?? new QuickBooksCurlHttpClient());
    }

    private function rateLimited(?int $retryAfter): QuickBooksDataServiceSession
    {
        return $this->querySession(null, self::fault(429, ''), self::retryAfter($retryAfter));
    }

    private static function retryAfter(?int $seconds, ?string $intuitTid = null): QuickBooksCurlHttpClient
    {
        $httpClient = Mockery::mock(QuickBooksCurlHttpClient::class);
        $httpClient->shouldReceive('retryAfterSeconds')->andReturn($seconds);
        $httpClient->shouldReceive('intuitTid')->andReturn($intuitTid);

        return $httpClient;
    }

    /**
     * @param list<QuickBooksDataServiceSession> $sessions one per expected attempt, in order
     */
    private function transport(
        array $sessions,
        (QuickBooksRateLimiter&MockInterface)|null $limiter = null,
        ?AbstractLogger $logger = null,
    ): QuickBooksTransport {
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
