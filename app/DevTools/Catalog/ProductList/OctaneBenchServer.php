<?php

declare(strict_types=1);

namespace App\DevTools\Catalog\ProductList;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\InvokedProcess;
use RuntimeException;

/**
 * A local Octane (Swoole) server on a free port, its output appended to storage/logs/octane.log like bin/serve does.
 */
final class OctaneBenchServer
{
    private const int READY_TIMEOUT_SECONDS = 90;

    private const string COMMAND = 'exec "$BENCH_PHP" artisan octane:start --server=swoole --host=127.0.0.1 --port="$BENCH_PORT" >> "$BENCH_LOG" 2>&1';

    private ?InvokedProcess $server = null;

    public function __construct(
        private readonly ProcessFactory $process,
    ) {}

    public static function logPath(): string
    {
        return \storage_path('logs/octane.log');
    }

    /**
     * @param array<string, string> $environment
     *
     * @return int The port the server listens on
     *
     * @throws RuntimeException When the server cannot start or does not become ready
     */
    public function start(array $environment): int
    {
        $port = self::freePort();

        $this->server = $this->process
            ->newPendingProcess()
            ->forever()
            ->path(\base_path())
            ->env([...$environment, 'BENCH_PHP' => \PHP_BINARY, 'BENCH_PORT' => (string) $port, 'BENCH_LOG' => self::logPath()])
            ->start(self::COMMAND);

        $this->awaitReady($port);

        return $port;
    }

    public function stop(): void
    {
        if ($this->server?->running() === true) {
            $this->server->stop(30);
        }

        $this->server = null;
    }

    /**
     * @throws RuntimeException
     */
    private function awaitReady(int $port): void
    {
        $deadline = \time() + self::READY_TIMEOUT_SECONDS;
        $client = new Client(['base_uri' => "http://127.0.0.1:{$port}", 'timeout' => 2, 'http_errors' => false]);

        while (\time() < $deadline && $this->server?->running() === true) {
            if (self::answersHealthCheck($client)) {
                return;
            }

            \usleep(500_000);
        }

        $this->stop();

        throw new RuntimeException('Octane did not answer /up in time; see storage/logs/octane.log.');
    }

    private static function answersHealthCheck(Client $client): bool
    {
        try {
            return $client->get('/up')->getStatusCode() === 200;
        } catch (GuzzleException) {
            return false;
        }
    }

    /**
     * @throws RuntimeException
     */
    private static function freePort(): int
    {
        $socket = \stream_socket_server('tcp://127.0.0.1:0');
        $name = $socket === false ? false : \stream_socket_get_name($socket, false);

        if ($socket !== false) {
            \fclose($socket);
        }

        if ($name === false) {
            throw new RuntimeException('Could not find a free local port.');
        }

        return (int) \mb_substr($name, (int) \mb_strrpos($name, ':') + 1);
    }
}
