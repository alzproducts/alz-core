<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversNothing]
final class QueueObservabilityServiceProviderTest extends TestCase
{
    /**
     * @param array<string, mixed> $payload
     */
    private function job(int $attempts, array $payload): Job
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('resolveName')->andReturn('TestJob');
        $job->shouldReceive('getQueue')->andReturn('low');
        $job->shouldReceive('attempts')->andReturn($attempts);
        $job->shouldReceive('payload')->andReturn($payload);

        return $job;
    }

    /**
     * @return array<string, mixed>
     */
    private function runJob(Job $job): array
    {
        $captured = [];
        Log::shouldReceive('info')->with('Job starting', Mockery::any())->once();
        Log::shouldReceive('info')->with('Job completed', Mockery::on(static function (array $ctx) use (&$captured): bool {
            $captured = $ctx;

            return true;
        }))->once();

        Event::dispatch(new JobProcessing('redis', $job));
        Event::dispatch(new JobProcessed('redis', $job));

        return $captured;
    }

    #[Test]
    public function first_attempt_logs_duration_and_queue_wait(): void
    {
        $ctx = $this->runJob($this->job(1, ['pushedAt' => (string) (\microtime(true) - 5)]));

        $this->assertArrayHasKey('duration_ms', $ctx);
        $this->assertGreaterThanOrEqual(5000, $ctx['queue_wait_ms']);
        $this->assertLessThan(6000, $ctx['queue_wait_ms']);
    }

    #[Test]
    public function retry_attempt_omits_queue_wait(): void
    {
        $ctx = $this->runJob($this->job(2, ['pushedAt' => (string) (\microtime(true) - 5)]));

        $this->assertArrayHasKey('duration_ms', $ctx);
        $this->assertArrayNotHasKey('queue_wait_ms', $ctx);
    }

    #[Test]
    public function payload_without_pushed_at_omits_queue_wait(): void
    {
        $ctx = $this->runJob($this->job(1, []));

        $this->assertArrayHasKey('duration_ms', $ctx);
        $this->assertArrayNotHasKey('queue_wait_ms', $ctx);
    }
}
