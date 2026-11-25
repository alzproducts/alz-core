<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging\Listeners;

use App\Infrastructure\Logging\GrafanaLogHandler;
use Illuminate\Log\Logger;
use Illuminate\Log\LogManager;
use Monolog\Logger as Monolog;

/**
 * Sends the records the `grafana` channel has buffered, when that channel has been used.
 */
final readonly class FlushGrafanaLogsListener
{
    public function __construct(private LogManager $logs) {}

    public function handle(): void
    {
        $channel = $this->logs->getChannels()['grafana'] ?? null;
        $monolog = $channel instanceof Logger ? $channel->getLogger() : null;

        if (! $monolog instanceof Monolog) {
            return;
        }

        foreach ($monolog->getHandlers() as $handler) {
            if ($handler instanceof GrafanaLogHandler) {
                $handler->flush();
            }
        }
    }
}
