<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Jobs\Mixpanel;

use App\Application\Mixpanel\Results\SyncOrdersToMixpanelResult;
use App\Application\Mixpanel\UseCases\SyncOrdersToMixpanelUseCase;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use App\Infrastructure\Jobs\Mixpanel\SyncOrdersToMixpanelJob;
use DateTimeImmutable;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SyncOrdersToMixpanelJob Unit Tests.
 *
 * Tests the job's success path and middleware configuration.
 * Exception handling (transient/permanent/unexpected) is tested in HandleApiExceptionsTest.
 * Failed callback logging is handled by the middleware.
 */
#[CoversClass(SyncOrdersToMixpanelJob::class)]
final class SyncOrdersToMixpanelJobTest extends TestCase
{
    private const string TEST_FROM = '2024-01-01 00:00:00';

    private const string TEST_TO = '2024-01-02 00:00:00';

    private SyncOrdersToMixpanelJob $job;

    private SyncOrdersToMixpanelUseCase&MockInterface $mockUseCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->job = new SyncOrdersToMixpanelJob(
            new DateTimeImmutable(self::TEST_FROM),
            new DateTimeImmutable(self::TEST_TO),
        );
        $this->mockUseCase = Mockery::mock(SyncOrdersToMixpanelUseCase::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Middleware Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_correct_middleware(): void
    {
        $middleware = $this->job->middleware();

        $this->assertCount(2, $middleware);
        $this->assertInstanceOf(ServiceCircuitBreaker::class, $middleware[0]);
        $this->assertInstanceOf(HandleApiExceptions::class, $middleware[1]);
    }

    /*
    |--------------------------------------------------------------------------
    | Success Path Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_executes_use_case_successfully(): void
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

        $this->job->handle($this->mockUseCase);
    }

    #[Test]
    public function it_delegates_to_use_case_with_empty_result(): void
    {
        $result = SyncOrdersToMixpanelResult::empty();

        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andReturn($result);

        $this->job->handle($this->mockUseCase);
    }

}
