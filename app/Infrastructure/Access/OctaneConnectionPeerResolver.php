<?php

declare(strict_types=1);

namespace App\Infrastructure\Access;

use App\Application\Contracts\Access\ConnectionPeerResolverInterface;

/**
 * Reads the TCP peer from the Swoole socket when running under Octane.
 *
 * intentional: REMOTE_ADDR is not trusted there. Octane's ConvertSwooleRequestToIlluminateRequest
 * leaves a `Remote-Addr` request header unprefixed and merges it over the server variables.
 * Other runtimes do not let a header set REMOTE_ADDR, so their value is returned unchanged.
 */
final readonly class OctaneConnectionPeerResolver implements ConnectionPeerResolverInterface
{
    /**
     * @param ?object $swooleWorkerState Octane's Swoole worker state; null outside a Swoole worker
     */
    public function __construct(
        private ?object $swooleWorkerState,
    ) {}

    public function resolve(?string $reportedAddress): ?string
    {
        return $this->swooleWorkerState === null
            ? $reportedAddress
            : self::socketPeer(\get_object_vars($this->swooleWorkerState));
    }

    /**
     * Look up the peer of the connection this worker is serving.
     *
     * Octane records the connection's file descriptor in its timer table, which exists only while
     * `octane.max_execution_time` is above zero. Without it the peer is unknown.
     *
     * @param array<array-key, mixed> $workerState
     */
    private static function socketPeer(array $workerState): ?string
    {
        $server = $workerState['server'] ?? null;
        $timerTable = $workerState['timerTable'] ?? null;
        $workerId = $workerState['workerId'] ?? null;

        if (!\is_object($server) || !\is_object($timerTable) || !\is_int($workerId)) {
            return null;
        }

        if (!\method_exists($server, 'getClientInfo') || !\method_exists($timerTable, 'get')) {
            return null;
        }

        $connection = $server->getClientInfo($timerTable->get((string) $workerId, 'fd'));
        $address = \is_array($connection) ? ($connection['remote_ip'] ?? null) : null;

        return \is_string($address) ? $address : null;
    }
}
