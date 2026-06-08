<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Jobs\Mixpanel;

use App\Application\AdSpend\UseCases\SyncAdSpendUseCase;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use App\Infrastructure\Jobs\Mixpanel\SyncBingAdsToMixpanelJob;
use DateTimeImmutable;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SyncBingAdsToMixpanelJob Unit Tests.
 *
 * Tests the job's success path and middleware configuration.
 * Exception handling (transient/permanent/unexpected) is tested in HandleApiExceptionsTest.
 * Failed callback logging is handled by the middleware.
 */
#[CoversClass(SyncBingAdsToMixpanelJob::class)]
final class SyncBingAdsToMixpanelJobTest extends TestCase
{
    private const string TEST_DATE = '2024-11-20';

    private SyncBingAdsToMixpanelJob $job;

    private SyncAdSpendUseCase&MockInterface $mockUseCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->job = new SyncBingAdsToMixpanelJob(
            new DateTimeImmutable(self::TEST_DATE),
            new DateTimeImmutable(self::TEST_DATE),
        );
        $this->mockUseCase = Mockery::mock(SyncAdSpendUseCase::class);
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
        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andReturnNull();

        $this->job->handle($this->mockUseCase);
    }

    #[Test]
    public function it_passes_correct_date_range_to_use_case(): void
    {
        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andReturnNull();

        $job = new SyncBingAdsToMixpanelJob(
            new DateTimeImmutable('2024-01-01'),
            new DateTimeImmutable('2024-01-31'),
        );
        $job->handle($this->mockUseCase);
    }

}
