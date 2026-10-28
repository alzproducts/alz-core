<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Support;

use HelpScout\Api\Http\RestClientBuilder;
use Override;

/**
 * SDK REST client builder whose requests are never retried in-process.
 *
 * The parent retries every ConnectException four times, POST included, which
 * can re-create a conversation that already landed and outlast the job timeout.
 * Retries belong to the queue job instead.
 */
final class NoRetryRestClientBuilder extends RestClientBuilder
{
    #[Override]
    protected function getRetryDecider(): callable
    {
        return static fn(): bool => false;
    }
}
