<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Jobs\Middleware;

use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Infrastructure\Jobs\ContactForm\ProcessContactSubmissionJob;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Pipeline\Pipeline;
use Mockery;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The first middleware in a job's list is the outermost, so HandleApiExceptions listed
 * before the breaker releases only after the breaker has counted the failure.
 */
#[CoversClass(HandleApiExceptions::class)]
#[CoversClass(ServiceCircuitBreaker::class)]
final class ApiMiddlewareStackTest extends TestCase
{
    private const string BREAKER_KEY = 'service_circuit_breaker:helpscout';

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        \app(RateLimiter::class)->clear(self::BREAKER_KEY);
    }

    #[Test]
    public function a_transient_with_retry_after_counts_toward_the_breaker_when_handled_outside_it(): void
    {
        $job = $this->fakeJob();

        $this->runThrough([new HandleApiExceptions(), ServiceCircuitBreaker::helpscout()], $job);

        self::assertSame(1, \app(RateLimiter::class)->attempts(self::BREAKER_KEY));
        self::assertSame(120, $job->releaseDelay);
    }

    #[Test]
    public function a_transient_with_retry_after_escapes_the_breaker_when_handled_inside_it(): void
    {
        $job = $this->fakeJob();

        $this->runThrough([ServiceCircuitBreaker::helpscout(), new HandleApiExceptions()], $job);

        self::assertSame(0, \app(RateLimiter::class)->attempts(self::BREAKER_KEY));
        self::assertSame(120, $job->releaseDelay);
    }

    #[Test]
    public function the_contact_submission_job_stack_counts_a_transient_with_retry_after(): void
    {
        $job = new ProcessContactSubmissionJob('submission-id', 'action-id');
        $queueJob = Mockery::mock(Job::class);
        $queueJob->expects('release')->with(120);
        $job->setJob($queueJob);

        $this->runThrough($job->middleware(), $job);

        self::assertSame(1, \app(RateLimiter::class)->attempts(self::BREAKER_KEY));
    }

    /**
     * @param list<object> $middleware
     */
    private function runThrough(array $middleware, object $job): void
    {
        new Pipeline(\app())
            ->send($job)
            ->through($middleware)
            ->then(static function (): never {
                throw new ExternalServiceUnavailableException('HelpScout', retryAfter: 120);
            });
    }

    /**
     * @return object{releaseDelay: int|null}
     */
    private function fakeJob(): object
    {
        return new class {
            public ?int $releaseDelay = null;

            public function release(int $delay = 0): void
            {
                $this->releaseDelay = $delay;
            }
        };
    }
}
