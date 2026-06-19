<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Jobs\Linnworks;

use App\Application\Linnworks\UseCases\SyncStockItemUseCase;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Jobs\Linnworks\SyncStockItemJob;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\MemoryTrackingMiddleware;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SyncStockItemJob Unit Tests.
 *
 * Tests:
 * - Middleware configuration
 * - Success path (delegates to use case)
 * Exception handling (transient/permanent/unexpected) is tested in HandleApiExceptionsTest.
 * Failed callback logging is handled by the middleware.
 */
#[CoversClass(SyncStockItemJob::class)]
final class SyncStockItemJobTest extends TestCase
{
    private SyncStockItemUseCase&MockInterface $mockUseCase;

    private Guid $stockItemId;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->mockUseCase = Mockery::mock(SyncStockItemUseCase::class);
        $this->stockItemId = Guid::fromTrusted('51d3090b-6a61-4dac-8ef5-b9f6f38082fa');
    }

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_correct_middleware(): void
    {
        $job = new SyncStockItemJob($this->stockItemId);
        $middleware = $job->middleware();

        $this->assertCount(3, $middleware);
        $this->assertInstanceOf(MemoryTrackingMiddleware::class, $middleware[0]);
        $this->assertInstanceOf(ServiceCircuitBreaker::class, $middleware[1]);
        $this->assertInstanceOf(HandleApiExceptions::class, $middleware[2]);
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
            ->once()
            ->with($this->stockItemId);

        $job = $this->createJob();
        $job->handle($this->mockUseCase);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function createJob(): SyncStockItemJob
    {
        return new SyncStockItemJob($this->stockItemId);
    }
}
