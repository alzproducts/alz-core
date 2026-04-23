<?php

declare(strict_types=1);

namespace App\Domain\Exceptions\Data;

use Override;
use Throwable;

/**
 * Feed data is malformed or unparseable.
 *
 * Thrown when fetched feed content cannot be processed due to data quality
 * issues (invalid XML, missing required elements, encoding problems).
 *
 * This is distinct from ExternalServiceUnavailableException (network/availability)
 * and indicates the feed source is returning invalid data.
 *
 * Retry semantics: Limited retry may help if source regenerates feed,
 * but persistent failures indicate source-side issues requiring investigation.
 */
final class MalformedFeedDataException extends AbstractDataException
{
    public function __construct(
        public readonly string $feedName,
        public readonly string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct('Malformed feed data', previous: $previous);
    }

    #[Override]
    public function context(): array
    {
        return ['feed_name' => $this->feedName, 'reason' => $this->reason];
    }
}
