<?php

declare(strict_types=1);

namespace App\Domain\ContactSubmission\Exceptions;

use App\Domain\Exceptions\DomainException;

/**
 * The requested operation is not supported for the conversion row's source.
 *
 * Raised when a source-restricted endpoint is invoked against a row from the wrong source —
 * e.g. `markNoQuoteExpected` (form-only, since calls have no quote tracking yet) called on a
 * call row. Maps to HTTP 409.
 *
 * `source` is a plain string (not the `PotentialConversionSource` enum) because that enum lives
 * in the Application layer — a Domain exception cannot import it. The throw site passes
 * `$row->source->value`.
 */
final class OperationNotSupportedForSourceException extends DomainException
{
    public function __construct(
        public readonly string $sourceId,
        public readonly string $source,
        public readonly string $operation,
    ) {
        parent::__construct('This operation is not supported for the conversion row source.');
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'source_id' => $this->sourceId,
            'source' => $this->source,
            'operation' => $this->operation,
        ];
    }
}
