<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Jobs\Middleware;

use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Infrastructure\Jobs\Middleware\HandleDatabaseExceptions;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(HandleDatabaseExceptions::class)]
final class HandleDatabaseExceptionsTest extends TestCase
{
    private HandleDatabaseExceptions $middleware;

    private MockInterface $mockJob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->middleware = new HandleDatabaseExceptions();
        $this->mockJob = Mockery::mock('JobWithInteractsWithQueue');
    }

    #[Test]
    public function it_passes_through_when_no_exception_occurs(): void
    {
        $called = false;

        $this->mockJob->shouldNotReceive('fail');

        $this->middleware->handle($this->mockJob, static function () use (&$called): void {
            $called = true;
        });

        $this->assertTrue($called);
    }

    #[Test]
    public function it_fails_job_on_permanent_infrastructure_exception(): void
    {
        $exception = new DatabaseOperationFailedException('insert', 'constraint violation');

        $this->mockJob->shouldReceive('fail')
            ->once()
            ->with($exception);

        $this->middleware->handle($this->mockJob, static function () use ($exception): void {
            throw $exception;
        });
    }

    #[Test]
    public function it_lets_record_not_found_exception_bubble_to_worker(): void
    {
        // RecordNotFoundException extends TransientApiFailure — must NOT be caught here
        // so it bubbles to Laravel's Worker for $backoff-based retry. This is the
        // retry-on-race invariant that the split between ResourceNotFoundException
        // (permanent) and RecordNotFoundException (transient) depends on.
        $exception = new RecordNotFoundException('ProductVariation', 12345);

        $this->mockJob->shouldNotReceive('fail');

        $this->expectException(RecordNotFoundException::class);

        $this->middleware->handle($this->mockJob, static function () use ($exception): void {
            throw $exception;
        });
    }
}
