<?php

declare(strict_types=1);

namespace App\Domain\Exceptions\Data;

use Throwable;

/**
 * Stored data does not match expected structure.
 *
 * Thrown when reading persisted data (database JSONB, cache, file) that
 * doesn't conform to the expected schema. Indicates data corruption,
 * migration issues, or manual tampering.
 *
 * This is distinct from:
 * - MalformedFeedDataException (external feed sources)
 * - MissingRequiredDataException (data dependency not satisfied)
 *
 * Resolution: Investigate data source, check for migration issues,
 * verify serialization/deserialization code paths.
 */
final class MalformedStoredDataException extends AbstractDataException
{
    /**
     * @param string $source Data source identifier (e.g., 'contact_submissions.product')
     * @param string $reason Why the data is malformed (e.g., 'missing required field: sku')
     */
    public function __construct(
        public readonly string $source,
        public readonly string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct('Malformed stored data', previous: $previous);
    }

    public function context(): array
    {
        return ['source' => $this->source, 'reason' => $this->reason];
    }
}
