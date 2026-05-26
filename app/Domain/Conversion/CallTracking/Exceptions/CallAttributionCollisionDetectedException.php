<?php

declare(strict_types=1);

namespace App\Domain\Conversion\CallTracking\Exceptions;

use App\Domain\Exceptions\DomainException;
use Override;

final class CallAttributionCollisionDetectedException extends DomainException
{
    /**
     * @param list<string> $visitIds
     */
    public function __construct(
        public readonly string $callId,
        public readonly array $visitIds,
        public readonly string $trackingNumber,
    ) {
        parent::__construct(
            'Call matched more than one visit inside the attribution window — excluded from the dashboard',
        );
    }

    /** @return array<string, mixed> */
    #[Override]
    public function context(): array
    {
        return [
            'call_id' => $this->callId,
            'visit_ids' => $this->visitIds,
            'tracking_number' => $this->trackingNumber,
        ];
    }
}
