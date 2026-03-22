<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Jobs\Linnworks;

use App\Application\Linnworks\UseCases\SyncStockItemWithCursorUseCase;
use App\Infrastructure\Jobs\Linnworks\SyncStockItemsWithCursorJob;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SyncStockItemsWithCursorJob Unit Tests.
 *
 * Tests:
 * - Middleware configuration
 * - Success path (delegates to cursor use case)
 * Exception handling (transient/permanent/unexpected) is tested in HandleApiExceptionsTest.
 * Failed callback logging is handled by the middleware.
 */
#[CoversClass(SyncStockItemsWithCursorJob::class)]
final class SyncStockItemsWithCursorJobTest extends TestCase
{
    private SyncStockItemWithCursorUseCase&MockInterface $mockUseCase;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->mockUseCase = Mockery::mock(SyncStockItemWithCursorUseCase::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_correct_middleware(): void
    {
        $job = new SyncStockItemsWithCursorJob();
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
    public function it_delegates_to_use_case(): void
    {
        $this->mockUseCase
            ->shouldReceive('execute')
            ->once();

        $job = $this->createJob();
        $job->handle($this->mockUseCase);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function createJob(): SyncStockItemsWithCursorJob
    {
        return new SyncStockItemsWithCursorJob();
    }
}
