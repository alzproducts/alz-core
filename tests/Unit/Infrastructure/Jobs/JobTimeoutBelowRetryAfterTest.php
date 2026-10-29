<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Jobs;

use App\Infrastructure\Jobs\Enums\QueueName;
use Illuminate\Contracts\Queue\ShouldQueue;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Tests\TestCase;
use Tests\Unit\Infrastructure\Jobs\Fixtures\SlowDefaultQueueJob;

/**
 * A job whose $timeout reaches its connection's retry_after is redelivered
 * to a second worker while the first run is still executing.
 */
#[CoversNothing]
final class JobTimeoutBelowRetryAfterTest extends TestCase
{
    /** @return iterable<string, array{class-string}> */
    public static function jobClasses(): iterable
    {
        $jobsDir = \dirname(__DIR__, 4) . '/app/Infrastructure/Jobs';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($jobsDir, RecursiveDirectoryIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = \mb_substr($file->getPathname(), \mb_strlen($jobsDir) + 1, -4);
            $class = 'App\\Infrastructure\\Jobs\\' . \str_replace('/', '\\', $relative);

            if (!\class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || !$reflection->implementsInterface(ShouldQueue::class)) {
                continue;
            }

            yield $relative => [$class];
        }
    }

    /** @param class-string $jobClass */
    #[Test]
    #[DataProvider('jobClasses')]
    public function job_timeout_is_below_the_retry_after_of_the_connection_consuming_its_queue(string $jobClass): void
    {
        $this->assertTimeoutBelowRetryAfter($jobClass);
    }

    #[Test]
    public function it_rejects_a_job_whose_timeout_reaches_the_retry_after(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("\$timeout (300s) must be below retry_after (120s) of the connection consuming queue 'default'");

        $this->assertTimeoutBelowRetryAfter(SlowDefaultQueueJob::class);
    }

    /** @param class-string $jobClass */
    private function assertTimeoutBelowRetryAfter(string $jobClass): void
    {
        $job = new ReflectionClass($jobClass);
        $timeout = $job->getDefaultProperties()['timeout'] ?? null;
        $this->assertIsInt($timeout, "{$jobClass} must declare an int \$timeout");

        $queue = $this->queueOf($job);
        $retryAfter = $this->retryAfterFor($queue);

        $this->assertLessThan(
            $retryAfter,
            $timeout,
            "{$jobClass} \$timeout ({$timeout}s) must be below retry_after ({$retryAfter}s) of the connection consuming queue '{$queue}'",
        );
    }

    /** @param ReflectionClass<object> $job */
    private function queueOf(ReflectionClass $job): string
    {
        $constructor = $job->getConstructor();
        $this->assertNotNull($constructor, "{$job->getName()} must assign its queue in a constructor");

        $file = $constructor->getDeclaringClass()->getFileName();
        $this->assertIsString($file);

        $lines = \file($file);
        $this->assertIsArray($lines);

        $source = \implode('', \array_slice(
            $lines,
            $constructor->getStartLine() - 1,
            $constructor->getEndLine() - $constructor->getStartLine() + 1,
        ));

        $matched = \preg_match('/->onQueue\(QueueName::(\w+)->value\)/', $source, $matches);
        $this->assertSame(1, $matched, "{$job->getName()} constructor must call \$this->onQueue(QueueName::<Case>->value)");

        $case = \constant(QueueName::class . '::' . $matches[1]);
        $this->assertInstanceOf(QueueName::class, $case);

        return $case->value;
    }

    private function retryAfterFor(string $queue): int
    {
        /** @var array<string, array{connection: string, queue: list<string>}> $supervisors */
        $supervisors = \config('horizon.defaults');

        $retryAfters = [];
        foreach ($supervisors as $supervisor) {
            if (\in_array($queue, $supervisor['queue'], true)) {
                $retryAfter = \config("queue.connections.{$supervisor['connection']}.retry_after");
                $this->assertIsInt($retryAfter);
                $retryAfters[] = $retryAfter;
            }
        }

        $this->assertNotEmpty($retryAfters, "No Horizon supervisor consumes queue '{$queue}'");

        return \min($retryAfters);
    }
}
