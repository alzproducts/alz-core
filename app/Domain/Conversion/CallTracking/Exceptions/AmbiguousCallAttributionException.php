<?php

declare(strict_types=1);

namespace App\Domain\Conversion\CallTracking\Exceptions;

use App\Domain\Exceptions\DomainException;
use Override;

/**
 * The unified dashboard view hides ambiguous calls via `visit_match_count = 1`, so
 * UI-driven submissions can't trigger this — it guards against direct-API callers.
 */
final class AmbiguousCallAttributionException extends DomainException
{
    public function __construct(
        public readonly string $callId,
    ) {
        parent::__construct(
            'Multiple visits match the call within the attribution window',
        );
    }

    /** @return array<string, mixed> */
    #[Override]
    public function context(): array
    {
        return [
            'call_id' => $this->callId,
        ];
    }
}
