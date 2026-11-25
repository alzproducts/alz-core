<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use App\Application\Contracts\ErrorReporterInterface;
use Illuminate\Http\Client\Factory;
use Illuminate\Log\LogManager;
use Monolog\Handler\BufferHandler;
use Monolog\Handler\WhatFailureGroupHandler;
use Monolog\Logger;

/**
 * Builds the `grafana` channel, which has no handlers unless its URL, user and key are all set.
 */
final readonly class GrafanaLogChannel
{
    private const int MAX_BUFFERED_RECORDS = 500;

    public function __construct(
        private Factory $http,
        private LogManager $logs,
        private ErrorReporterInterface $errorReporter,
        private GrafanaLogRedactor $redactor,
    ) {}

    /**
     * @param array{level: 'alert'|'critical'|'debug'|'emergency'|'error'|'info'|'notice'|'warning', url: ?string, user: ?string, key: ?string, env: string, service: ?string} $config
     */
    public function __invoke(array $config): Logger
    {
        [$url, $user, $key] = [(string) $config['url'], (string) $config['user'], (string) $config['key']];

        if (\in_array('', [$url, $user, $key], true)) {
            return new Logger($config['env']);
        }

        $labels = ['app' => 'alz-core', 'env' => $config['env'], 'service' => (string) $config['service']];
        $sender = $this->sender($url, $user, $key, \array_filter($labels, static fn(string $label): bool => $label !== ''));

        return new Logger($config['env'], [new BufferHandler(
            new WhatFailureGroupHandler([$sender]),
            self::MAX_BUFFERED_RECORDS,
            $config['level'],
            flushOnOverflow: true,
        )]);
    }

    /**
     * @param array<string, string> $labels
     */
    private function sender(string $url, string $user, string $key, array $labels): GrafanaLogSender
    {
        return new GrafanaLogSender(
            $this->http,
            $this->logs,
            $this->errorReporter,
            $this->redactor,
            $url,
            $user,
            $key,
            $labels,
        );
    }
}
