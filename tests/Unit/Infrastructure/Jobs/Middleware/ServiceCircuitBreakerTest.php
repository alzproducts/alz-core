<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Jobs\Middleware;

use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\TransientApiFailure;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use Illuminate\Cache\RateLimiter;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

#[CoversClass(ServiceCircuitBreaker::class)]
final class ServiceCircuitBreakerTest extends TestCase
{
    private ServiceCircuitBreaker $middleware;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->middleware = ServiceCircuitBreaker::linnworks();

        \app(RateLimiter::class)->clear('service_circuit_breaker:linnworks');
    }

    #[Test]
    public function it_passes_through_on_success(): void
    {
        $called = false;

        $this->middleware->handle($this->fakeJob(), static function () use (&$called): void {
            $called = true;
        });

        $this->assertTrue($called);
    }

    #[Test]
    public function it_rethrows_transient_failures_instead_of_releasing(): void
    {
        $exception = new ExternalServiceUnavailableException('Linnworks');

        $this->expectException(TransientApiFailure::class);

        $this->middleware->handle($this->fakeJob(), static function () use ($exception): never {
            throw $exception;
        });
    }

    #[Test]
    public function it_does_not_catch_non_transient_exceptions(): void
    {
        $this->expectException(InvalidApiRequestException::class);

        $this->middleware->handle($this->fakeJob(), static function (): never {
            throw new InvalidApiRequestException('Linnworks', 'bad request');
        });
    }

    #[Test]
    public function it_does_not_catch_generic_exceptions(): void
    {
        $this->expectException(RuntimeException::class);

        $this->middleware->handle($this->fakeJob(), static function (): never {
            throw new RuntimeException('unexpected');
        });
    }

    #[Test]
    public function it_increments_failure_counter_on_transient_failure(): void
    {
        $limiter = \app(RateLimiter::class);
        $key = 'service_circuit_breaker:linnworks';

        $this->assertSame(0, $limiter->attempts($key));

        try {
            $this->middleware->handle($this->fakeJob(), static function (): never {
                throw new ExternalServiceUnavailableException('Linnworks');
            });
        } catch (TransientApiFailure) {
        }

        $this->assertSame(1, $limiter->attempts($key));
    }

    #[Test]
    public function it_releases_job_when_circuit_is_tripped(): void
    {
        $limiter = \app(RateLimiter::class);
        $key = 'service_circuit_breaker:linnworks';

        for ($i = 0; $i < 10; $i++) {
            $limiter->hit($key, 300);
        }

        $job = $this->fakeJob();
        $handlerCalled = false;

        $this->middleware->handle($job, static function () use (&$handlerCalled): void {
            $handlerCalled = true;
        });

        $this->assertFalse($handlerCalled, 'Handler should not execute when circuit is tripped');
        $this->assertTrue($job->wasReleased, 'Job should be released when circuit is tripped');
        $this->assertGreaterThan(0, $job->releaseDelay);
    }

    /**
     * @return object{wasReleased: bool, releaseDelay: int}
     */
    private function fakeJob(): object
    {
        return new class {
            public bool $wasReleased = false;

            public int $releaseDelay = 0;

            public function release(int $delay = 0): void
            {
                $this->wasReleased = true;
                $this->releaseDelay = $delay;
            }
        };
    }
}
