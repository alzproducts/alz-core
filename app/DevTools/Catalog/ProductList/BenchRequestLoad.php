<?php

declare(strict_types=1);

namespace App\DevTools\Catalog\ProductList;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Utils;
use Illuminate\Contracts\Config\Repository;
use Psr\Http\Message\ResponseInterface;

/**
 * Sends the list request mix at a fixed rate, without waiting for responses, as the local-bypass approved user.
 * The bypass value goes into the request header from config and never into a process argument.
 */
final readonly class BenchRequestLoad
{
    public const float REQUESTS_PER_SECOND = 2.0;

    public function __construct(
        private Repository $config,
    ) {}

    /**
     * @param Closure(): void $onWarmupSettled Called once every warm-up response is in, before the first measured request
     *
     * @return array<int, int> Client-seen status code => count, measured requests only (0 = transport failure)
     */
    public function run(int $port, int $warmup, int $requests, Closure $onWarmupSettled): array
    {
        $handler = new CurlMultiHandler();
        $client = new Client([
            'handler' => HandlerStack::create($handler),
            'base_uri' => "http://127.0.0.1:{$port}",
            'http_errors' => false,
            'timeout' => 60,
            'headers' => ['Accept' => 'application/json', 'X-Local-Bypass' => $this->config->get('services.supabase.local_bypass_secret')],
        ]);

        $this->sendPaced($client, $handler, 0, $warmup);
        $onWarmupSettled();

        return \array_count_values($this->sendPaced($client, $handler, $warmup, $warmup + $requests));
    }

    /**
     * @return list<int> Status code per request
     */
    private function sendPaced(Client $client, CurlMultiHandler $handler, int $from, int $to): array
    {
        $interval = 1 / self::REQUESTS_PER_SECOND;
        $startedAt = \microtime(true);
        $promises = [];

        for ($index = $from; $index < $to; $index++) {
            $promises[] = $client->getAsync(ProductListRequestShapes::benchUri($index))->then(
                static fn(ResponseInterface $response): int => $response->getStatusCode(),
                static fn(): int => 0,
            );

            self::tickUntil($handler, $startedAt + (($index - $from + 1) * $interval));
        }

        /** @var list<int> $statuses */
        $statuses = Utils::all($promises)->wait();

        return $statuses;
    }

    private static function tickUntil(CurlMultiHandler $handler, float $deadline): void
    {
        while (\microtime(true) < $deadline) {
            $handler->tick();
            \usleep(2_000);
        }
    }
}
