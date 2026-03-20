<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Jobs\Mixpanel;

use App\Infrastructure\Jobs\Mixpanel\SyncOrdersToMixpanelJob;
use App\Application\Mixpanel\Results\SyncOrdersToMixpanelResult;
use App\Application\Mixpanel\UseCases\SyncOrdersToMixpanelUseCase;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\Exceptions\Data\MissingRequiredDataException;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * SyncOrdersToMixpanelJob Unit Tests.
 *
 * Tests the job's exception handling and retry logic:
 * - Success path with logging
 * - Permanent failures (UnexpectedApiResultException, AuthenticationExpiredException, MissingRequiredDataException)
 * - Transient failures with custom retry (ExternalServiceUnavailableException)
 * - Failed callback logging
 */
#[CoversClass(SyncOrdersToMixpanelJob::class)]
final class SyncOrdersToMixpanelJobTest extends TestCase
{
    private const string TEST_FROM = '2024-01-01 00:00:00';

    private const string TEST_TO = '2024-01-02 00:00:00';

    private SyncOrdersToMixpanelUseCase&MockInterface $mockUseCase;

    private LoggerInterface&MockInterface $mockLogger;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->mockUseCase = Mockery::mock(SyncOrdersToMixpanelUseCase::class);
        $this->mockLogger = Mockery::mock(LoggerInterface::class)->shouldIgnoreMissing();
    }

    /*
    |--------------------------------------------------------------------------
    | Success Path Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_executes_use_case_and_logs_success(): void
    {
        $result = new SyncOrdersToMixpanelResult(
            ordersInRange: 10,
            skipped: 5,
            synced: 5,
            checkoutEventsCreated: 5,
            productEventsCreated: 25,
        );

        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andReturn($result);

        $this->mockLogger->shouldReceive('info')
            ->once()
            ->with('Mixpanel order sync job starting', Mockery::type('array'));

        $this->mockLogger->shouldReceive('info')
            ->once()
            ->with('Mixpanel order sync job completed', Mockery::on(static fn(array $context): bool => $context['orders_in_range'] === 10
                    && $context['skipped'] === 5
                    && $context['synced'] === 5
                    && $context['checkout_events'] === 5
                    && $context['product_events'] === 25));

        $job = $this->createJob();
        $job->handle($this->mockUseCase, $this->mockLogger);
    }

    #[Test]
    public function it_logs_start_message_with_date_range(): void
    {
        $result = SyncOrdersToMixpanelResult::empty();

        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andReturn($result);

        $this->mockLogger->shouldReceive('info')
            ->once()
            ->with('Mixpanel order sync job starting', [
                'from' => self::TEST_FROM,
                'to' => self::TEST_TO,
            ]);

        $this->mockLogger->shouldReceive('info')
            ->once()
            ->with('Mixpanel order sync job completed', Mockery::type('array'));

        $job = $this->createJob();
        $job->handle($this->mockUseCase, $this->mockLogger);
    }

    /*
    |--------------------------------------------------------------------------
    | Permanent Failures - UnexpectedApiResultException
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_fails_immediately_on_unexpected_api_result_exception(): void
    {
        $exception = new UnexpectedApiResultException('Mixpanel', 'Export returned empty result');

        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andThrow($exception);

        $job = $this->createJobMock();
        $job->shouldReceive('fail')->once()->with($exception);
        $job->shouldReceive('attempts')->andReturn(1);

        $this->expectException(UnexpectedApiResultException::class);

        $job->handle($this->mockUseCase, $this->mockLogger);
    }

    /*
    |--------------------------------------------------------------------------
    | Permanent Failures - AuthenticationExpiredException
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_fails_immediately_on_authentication_expired_exception(): void
    {
        $exception = new AuthenticationExpiredException('Mixpanel');

        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andThrow($exception);

        $job = $this->createJobMock();
        $job->shouldReceive('fail')->once()->with($exception);
        $job->shouldReceive('attempts')->andReturn(1);

        $this->expectException(AuthenticationExpiredException::class);

        $job->handle($this->mockUseCase, $this->mockLogger);
    }

    #[Test]
    public function it_does_not_retry_on_authentication_expired_exception(): void
    {
        $exception = new AuthenticationExpiredException('Mixpanel');

        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andThrow($exception);

        $job = $this->createJobMock();
        $job->shouldReceive('fail')->once()->with($exception);
        $job->shouldNotReceive('release'); // Should NOT release for retry
        $job->shouldReceive('attempts')->andReturn(2);

        $this->expectException(AuthenticationExpiredException::class);

        $job->handle($this->mockUseCase, $this->mockLogger);
    }

    /*
    |--------------------------------------------------------------------------
    | Permanent Failures - MissingRequiredDataException
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_fails_immediately_on_missing_required_data_exception(): void
    {
        $exception = new MissingRequiredDataException(
            dataType: 'customer trade status',
            operation: 'Mixpanel order sync',
            resolution: 'Run customer sync first',
        );

        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andThrow($exception);

        $job = $this->createJobMock();
        $job->shouldReceive('fail')->once()->with($exception);
        $job->shouldReceive('attempts')->andReturn(1);

        $this->expectException(MissingRequiredDataException::class);

        $job->handle($this->mockUseCase, $this->mockLogger);
    }

    #[Test]
    public function it_does_not_retry_on_missing_required_data_exception(): void
    {
        $exception = new MissingRequiredDataException(
            dataType: 'customer trade status',
            operation: 'Mixpanel order sync',
        );

        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andThrow($exception);

        $job = $this->createJobMock();
        $job->shouldReceive('fail')->once()->with($exception);
        $job->shouldNotReceive('release'); // Should NOT release for retry
        $job->shouldReceive('attempts')->andReturn(2);

        $this->expectException(MissingRequiredDataException::class);

        $job->handle($this->mockUseCase, $this->mockLogger);
    }

    /*
    |--------------------------------------------------------------------------
    | Transient Failures - ExternalServiceUnavailableException with retryAfter
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_releases_with_api_provided_retry_after(): void
    {
        $exception = new ExternalServiceUnavailableException('Mixpanel', retryAfter: 180);

        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andThrow($exception);

        $this->mockLogger->shouldReceive('warning')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => $message === 'Mixpanel order sync service unavailable, will retry'
                    && $context['service'] === 'Mixpanel'
                    && $context['retry_after'] === 180);

        $job = $this->createJobMock();
        $job->shouldReceive('release')->once()->with(180);
        $job->shouldReceive('attempts')->andReturn(1);

        $job->handle($this->mockUseCase, $this->mockLogger);
    }

    /*
    |--------------------------------------------------------------------------
    | Transient Failures - ExternalServiceUnavailableException without retryAfter
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_rethrows_exception_when_retry_after_is_null(): void
    {
        $exception = new ExternalServiceUnavailableException('Mixpanel');

        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andThrow($exception);

        $this->mockLogger->shouldReceive('warning')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => $context['retry_after'] === null);

        $job = $this->createJobMock();
        $job->shouldNotReceive('release');
        $job->shouldReceive('attempts')->andReturn(3);

        $this->expectException(ExternalServiceUnavailableException::class);

        $job->handle($this->mockUseCase, $this->mockLogger);
    }

    /*
    |--------------------------------------------------------------------------
    | Unexpected Exception Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_fails_immediately_on_unexpected_exception(): void
    {
        $exception = new RuntimeException('Unexpected database error');

        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andThrow($exception);

        $job = $this->createJobMock();
        $job->shouldReceive('fail')->once()->with($exception);
        $job->shouldReceive('attempts')->andReturn(1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected database error');

        $job->handle($this->mockUseCase, $this->mockLogger);
    }

    #[Test]
    public function it_does_not_retry_on_unexpected_exception(): void
    {
        $exception = new RuntimeException('Unknown error');

        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andThrow($exception);

        $job = $this->createJobMock();
        $job->shouldReceive('fail')->once()->with($exception);
        $job->shouldNotReceive('release'); // Should NOT release for retry
        $job->shouldReceive('attempts')->andReturn(2);

        $this->expectException(RuntimeException::class);

        $job->handle($this->mockUseCase, $this->mockLogger);
    }

    /*
    |--------------------------------------------------------------------------
    | Failed Callback Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_logs_error_on_failed_callback(): void
    {
        $exception = new RuntimeException('Something went wrong');

        Log::shouldReceive('critical')
            ->once()
            ->with('Mixpanel order sync job failed permanently', [
                'from' => self::TEST_FROM,
                'to' => self::TEST_TO,
                'exception' => RuntimeException::class,
                'message' => 'Something went wrong',
                'attempts' => 5,
            ]);

        $job = $this->createJobMock();
        $job->shouldReceive('attempts')->andReturn(5);

        $job->failed($exception);
    }

    #[Test]
    public function it_logs_external_service_exception_class_on_failure(): void
    {
        $exception = new ExternalServiceUnavailableException('Mixpanel');

        Log::shouldReceive('error')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => $context['exception'] === ExternalServiceUnavailableException::class
                    && $context['message'] === "External service 'Mixpanel' is unavailable");

        $job = $this->createJobMock();
        $job->shouldReceive('attempts')->andReturn(5);

        $job->failed($exception);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    private function createJob(): SyncOrdersToMixpanelJob
    {
        return new SyncOrdersToMixpanelJob(
            new DateTimeImmutable(self::TEST_FROM),
            new DateTimeImmutable(self::TEST_TO),
        );
    }

    /**
     * Create a partial mock that runs the real constructor.
     *
     * Uses Mockery's bracket syntax (partial test double) instead of makePartial()
     * because makePartial() doesn't run constructors. This allows the job's
     * constructor to call onQueue() without triggering BadMethodCallException.
     *
     * @return SyncOrdersToMixpanelJob&MockInterface
     */
    private function createJobMock(): SyncOrdersToMixpanelJob&MockInterface
    {
        /** @var SyncOrdersToMixpanelJob&MockInterface $mock */
        $mock = Mockery::mock(
            SyncOrdersToMixpanelJob::class . '[fail,release,attempts]',
            [new DateTimeImmutable(self::TEST_FROM), new DateTimeImmutable(self::TEST_TO)],
        );

        return $mock;
    }
}
