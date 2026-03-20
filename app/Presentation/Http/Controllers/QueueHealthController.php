<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Queue;

/**
 * Queue health check endpoint for monitoring and alerting.
 *
 * Returns queue depth per queue and overall health status.
 * Protected by HorizonBasicAuthMiddleware (same credentials as Horizon dashboard).
 */
final class QueueHealthController
{
    private const array QUEUES = ['high', 'default', 'low'];

    public function __invoke(): JsonResponse
    {
        $depths = [];

        foreach (self::QUEUES as $queue) {
            $depths[$queue] = Queue::size($queue);
        }

        $totalDepth = \array_sum($depths);

        return new JsonResponse([
            'status' => 'ok',
            'queues' => $depths,
            'total_depth' => $totalDepth,
        ]);
    }
}
