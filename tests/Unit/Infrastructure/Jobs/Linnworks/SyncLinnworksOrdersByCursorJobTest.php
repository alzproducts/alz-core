<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Jobs\Linnworks;

use App\Application\Linnworks\UseCases\SyncLinnworksCursorUseCase;
use App\Infrastructure\Jobs\Linnworks\SyncLinnworksOrdersByCursorJob;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SyncLinnworksOrdersByCursorJob Unit Tests.
 *
 * Tests:
 * - Job properties (uniqueId)
 * - Middleware configuration
 * - Success path (delegates to cursor use case)
 * Exception handling (transient/permanent/unexpected) is tested in HandleApiExceptionsTest.
 * Failed callback logging is handled by the middleware.
 */
#[CoversClass(SyncLinnworksOrdersByCursorJob::class)]
final class SyncLinnworksOrdersByCursorJobTest extends TestCase
{
    private SyncLinnworksCursorUseCase&MockInterface $mockUseCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->mockUseCase = Mockery::mock(SyncLinnworksCursorUseCase::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Job Properties
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_has_fixed_unique_id(): void
    {
        $job = new SyncLinnworksOrdersByCursorJob();
        $this->assertSame('sync-linnworks-orders-cursor', $job->uniqueId());
    }

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_correct_middleware(): void
    {
        $job = new SyncLinnworksOrdersByCursorJob();
        $middleware = $job->middleware();

        $this->assertCount(2, $middleware);
        $this->assertInstanceOf(ThrottlesExceptions::class, $middleware[0]);
        $this->assertInstanceOf(HandleApiExceptions::class, $middleware[1]);
    }

    /*
    |--------------------------------------------------------------------------
    | Success Path
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_delegates_to_cursor_use_case(): void
    {
        $this->mockUseCase
            ->shouldReceive('execute')
            ->once();

        $job = $this->createJobMock();
        $job->handle($this->mockUseCase);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function createJobMock(): SyncLinnworksOrdersByCursorJob&MockInterface
    {
        $job = Mockery::mock(SyncLinnworksOrdersByCursorJob::class)->makePartial();
        $job->allows('onQueue');
        $job->__construct();

        return $job;
    }
}
