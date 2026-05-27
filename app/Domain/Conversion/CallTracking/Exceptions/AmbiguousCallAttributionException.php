<?php

declare(strict_types=1);

namespace App\Domain\Conversion\CallTracking\Exceptions;

use App\Domain\Exceptions\DomainException;
use Override;

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
