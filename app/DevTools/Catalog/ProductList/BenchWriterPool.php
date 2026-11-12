<?php

declare(strict_types=1);

namespace App\DevTools\Catalog\ProductList;

use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Throwable;

/**
 * Runs each background writer in its own forked process until stop() is called.
 * Children are told to stop through a file, since signalling them by pid is not available here.
 */
final class BenchWriterPool
{
    /** @var array<int, string> Child pid => writer name */
    private array $children = [];

    private string $stopFile = '';

    private float $deadline = 0.0;

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Filesystem $files,
    ) {}

    /**
     * @param list<BenchWriter> $writers
     * @param float $maxRunSeconds Children stop on their own after this long, should the parent die first
     *
     * @throws RuntimeException When a writer cannot be forked
     */
    public function start(array $writers, BenchWriteLoad $load, float $maxRunSeconds): void
    {
        $this->deadline = \microtime(true) + $maxRunSeconds;
        $this->stopFile = \base_path('tmp/cor-258/bench-stop-' . \getmypid());
        $this->files->delete($this->stopFile);

        // intentional: a forked child inherits any open PDO socket, and closing it there would end the parent's session.
        $this->database->purge();

        foreach ($writers as $writer) {
            $this->fork($writer, $load);
        }
    }

    /**
     * @return array<string, int> Writer name => exit status, 0 when every write succeeded
     */
    public function stop(): array
    {
        $this->files->put($this->stopFile, 'stop');
        $exits = [];

        foreach ($this->children as $pid => $name) {
            $exits[$name] = self::waitForExit($pid);
        }

        $this->files->delete($this->stopFile);
        $this->children = [];

        return $exits;
    }

    /**
     * @return int The child's exit code, or 1 when it did not exit normally
     */
    private static function waitForExit(int $pid): int
    {
        $status = 0;
        \pcntl_waitpid($pid, $status);

        if (! \is_int($status) || ! \pcntl_wifexited($status)) {
            return 1;
        }

        $exitCode = \pcntl_wexitstatus($status);

        return $exitCode === false ? 1 : $exitCode;
    }

    /**
     * @throws RuntimeException
     */
    private function fork(BenchWriter $writer, BenchWriteLoad $load): void
    {
        $pid = \pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException("Could not fork the {$writer->kind->value} writer.");
        }

        if ($pid === 0) {
            $this->runChild($writer, $load);
        }

        $this->children[$pid] = $writer->kind->value;
    }

    private function runChild(BenchWriter $writer, BenchWriteLoad $load): never
    {
        $this->database->purge();

        try {
            $writes = $this->loop($writer, $load);
            \fwrite(\STDOUT, "  writer {$writer->kind->value}: {$writes} writes\n");
            $exitCode = 0;
        } catch (Throwable $e) { // @ignoreException - process boundary: a forked writer must never unwind into the parent's code path
            \fwrite(\STDERR, "  writer {$writer->kind->value} failed: {$e->getMessage()}\n");
            $exitCode = 1;
        }

        exit($exitCode);
    }

    /**
     * @throws RuntimeException
     */
    private function loop(BenchWriter $writer, BenchWriteLoad $load): int
    {
        $nextAt = \microtime(true) + $writer->startDelaySeconds;
        $writes = 0;

        while (! \is_file($this->stopFile) && \microtime(true) < $this->deadline) {
            if (\microtime(true) >= $nextAt) {
                $load->perform($writer->kind);
                $writes++;
                $nextAt = $writer->intervalSeconds === null ? \INF : $nextAt + $writer->intervalSeconds;
            }

            \usleep(200_000);
        }

        return $writes;
    }
}
