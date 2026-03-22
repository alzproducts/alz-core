<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Jobs\Feeds;

use App\Application\Feeds\ProcessProductSearchFeedUseCase;
use App\Infrastructure\Jobs\Feeds\ProcessProductSearchFeedJob;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ProcessProductSearchFeedJob Unit Tests.
 *
 * Tests job properties and success path only. Exception handling
 * (transient/permanent/unexpected) is tested in HandleApiExceptionsTest.
 */
#[CoversClass(ProcessProductSearchFeedJob::class)]
final class ProcessProductSearchFeedJobTest extends TestCase
{
    private ProcessProductSearchFeedUseCase&MockInterface $mockUseCase;

    private ProcessProductSearchFeedJob $job;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->mockUseCase = Mockery::mock(ProcessProductSearchFeedUseCase::class);
        $this->job = new ProcessProductSearchFeedJob();
    }

    #[Test]
    public function it_returns_correct_middleware(): void
    {
        $middleware = $this->job->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(HandleApiExceptions::class, $middleware[0]);
    }

    #[Test]
    public function it_executes_use_case_successfully(): void
    {
        $this->mockUseCase
            ->shouldReceive('execute')
            ->once()
            ->andReturnNull();

        $this->job->handle($this->mockUseCase);
    }
}
