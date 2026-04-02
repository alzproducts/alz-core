<?php

declare(strict_types=1);

namespace App\Domain\Exceptions\Infrastructure;

/**
 * Thrown when a batch persistence operation partially fails.
 *
 * Some entities were saved successfully, but others failed. Callers should
 * inspect the succeeded/failed counts and failed identifiers for context.
 *
 * This is a permanent error — the failed items had individual, non-transient failures
 * (constraint violations, schema errors). Transient failures (DB unavailable) are
 * thrown separately as ExternalServiceUnavailableException.
 */
final class PartialPersistenceFailureException extends AbstractInfrastructureException
{
    /**
     * @param list<int|string> $failedReferences Identifiers of entities that failed to persist
     */
    public function __construct(
        public readonly int $succeeded,
        public readonly int $failed,
        public readonly array $failedReferences,
    ) {
        parent::__construct('Partial persistence failure');
    }

    public function context(): array
    {
        return [
            'succeeded' => $this->succeeded,
            'failed' => $this->failed,
            'failed_references' => $this->failedReferences,
        ];
    }
}
