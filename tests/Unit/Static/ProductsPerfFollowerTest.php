<?php

declare(strict_types=1);

namespace Tests\Unit\Static;

use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The `products-perf.sh` Octane log follower against a throwaway container: what the container prints reaches the log as the
 * container itself reads it, and its stop step leaves neither half of the pipe running. Needs a Docker daemon: `make test-docker`.
 */
#[CoversNothing]
#[Group('docker')]
final class ProductsPerfFollowerTest extends TestCase
{
    private const string SCRIPT = '.agents/skills/verify/features/products-perf.sh';

    private const string IMAGE = 'busybox:1.36';

    private const string LOG = '/logs/octane-stdout.log';

    /** `LOG_FLUSH_SECONDS` in `products-perf.php`: how long the load waits for the log before reading it. */
    private const int LOG_FLUSH_SECONDS = 3;

    private string $root;

    private string $logs;

    private string $state;

    private string $container = '';

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $root = \sys_get_temp_dir() . '/products-perf-follower-' . \bin2hex(\random_bytes(6));
        \mkdir($root, 0o700, true);
        $this->root = (string) \realpath($root);
        $this->logs = "{$this->root}/logs";
        $this->state = "{$this->root}/state";
        \mkdir($this->logs);
        \mkdir($this->state, 0o700);
        // intentional: the container runs as www-data (uid 33), as the app image does, and writes through the bind mount.
        \chmod($this->logs, 0o777);

        $this->container = \mb_trim(self::docker(
            'run',
            '-d',
            '--rm',
            '--user',
            '33:33',
            '-v',
            "{$this->logs}:/logs",
            self::IMAGE,
            'sleep',
            '600',
        )->getOutput());
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->follower('follow_stop', $this->state);

        if ($this->container !== '') {
            (new Process(['docker', 'rm', '-f', $this->container]))->run();
        }

        (new Process(['rm', '-rf', $this->root]))->run();

        parent::tearDown();
    }

    #[Test]
    public function the_container_reads_every_line_it_printed_within_the_flush_wait(): void
    {
        $this->assertFileDoesNotExist("{$this->logs}/octane-stdout.log");
        $this->assertTrue($this->follower('follow_start', $this->container, self::LOG, $this->state)->isSuccessful());

        // The load reads the log once at its window start and again at its end: the second read must see the growth.
        $warmup = self::lines(1, 5);
        $this->assertSame(\mb_strlen($warmup, '8bit'), $this->printAndRead('warmup', $warmup, \mb_strlen($warmup, '8bit')));

        $measured = self::lines(6, 25);
        $total = \mb_strlen($warmup . $measured, '8bit');
        $this->assertSame($total, $this->printAndRead('measured', $measured, $total));
    }

    #[Test]
    public function the_stop_step_leaves_neither_docker_logs_nor_the_writer_running(): void
    {
        $this->assertTrue($this->follower('follow_start', $this->container, self::LOG, $this->state)->isSuccessful());
        $pids = \array_map(\intval(...), \file("{$this->state}/follow.pids", \FILE_IGNORE_NEW_LINES) ?: []);
        $this->assertCount(2, $pids);
        $this->assertEqualsCanonicalizing($pids, $this->followerProcesses(), 'the writer and docker logs -f run before the stop');

        $this->assertTrue($this->follower('follow_stop', $this->state)->isSuccessful());

        $this->assertSame([], $this->followerProcesses());
        $this->assertSame([], \array_values(\array_filter($pids, self::alive(...))));
    }

    private function follower(string $function, string ...$arguments): Process
    {
        $process = new Process(
            ['bash', '-c', 'source "$1"; shift; "$@"', 'products-perf-follower-test', self::SCRIPT, $function, ...\array_values($arguments)],
            \dirname(__DIR__, 3),
        );
        $process->setTimeout(30);
        $process->run();

        return $process;
    }

    /**
     * Prints $lines on the container's stdout, then reads the log's size inside the container until it is $expected or
     * `LOG_FLUSH_SECONDS` have passed since the last line.
     */
    private function printAndRead(string $name, string $lines, int $expected): ?int
    {
        \file_put_contents("{$this->logs}/{$name}.txt", $lines);
        self::docker('exec', $this->container, 'sh', '-c', "cat /logs/{$name}.txt >/proc/1/fd/1");
        $deadline = \microtime(true) + self::LOG_FLUSH_SECONDS;

        $size = $this->sizeInContainer();
        while ($size !== $expected && \microtime(true) + 0.5 < $deadline) {
            \usleep(100_000);
            $size = $this->sizeInContainer();
        }

        return $size;
    }

    private static function lines(int $from, int $to): string
    {
        $lines = '';
        for ($i = $from; $i <= $to; $i++) {
            $lines .= "GET /api/products?page={$i} 200 {$i}.25 ms\n";
        }

        return $lines;
    }

    private function sizeInContainer(): ?int
    {
        $stat = new Process(['docker', 'exec', $this->container, 'stat', '-c', '%s', self::LOG]);
        $stat->run();

        return $stat->isSuccessful() ? (int) \mb_trim($stat->getOutput()) : null;
    }

    /**
     * @return list<int> PIDs of this container's `docker logs -f` and `docker exec -i` processes
     */
    private function followerProcesses(): array
    {
        $pids = [];

        foreach (["docker logs -f {$this->container}", "docker exec -i {$this->container}"] as $pattern) {
            $pgrep = new Process(['pgrep', '-f', $pattern]);
            $pgrep->run();
            $pids = [...$pids, ...\array_map(\intval(...), \array_values(\array_filter(\explode("\n", $pgrep->getOutput()))))];
        }

        return $pids;
    }

    private static function alive(int $pid): bool
    {
        return (new Process(['kill', '-0', (string) $pid]))->run() === 0;
    }

    private static function docker(string ...$command): Process
    {
        $process = new Process(['docker', ...\array_values($command)]);
        $process->setTimeout(60);

        return $process->mustRun();
    }
}
