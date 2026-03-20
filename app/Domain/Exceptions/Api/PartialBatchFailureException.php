<?php

declare(strict_types=1);

namespace App\Domain\Exceptions\Api;

use App\Domain\Exceptions\DomainException;

/**
 * Wraps multiple independent API failures from a batch operation.
 *
 * Modelled after .NET's AggregateException — when a batch is chunked and
 * some chunks succeed while others fail, this exception carries the failures
 * while the successful results are returned normally.
 *
 * Callers inspect $failures to classify each one individually (transient vs permanent).
 */
final class PartialBatchFailureException extends DomainException
{
    /**
     * @param list<AbstractApiException> $failures The actual domain exceptions from failed chunks
     * @param string $serviceName The external service that partially failed
     */
    public function __construct(
        public readonly array $failures,
        public readonly string $serviceName,
    ) {
        $count = \count($failures);
        parent::__construct("{$serviceName}: {$count} batch chunk(s) failed");
    }
}
