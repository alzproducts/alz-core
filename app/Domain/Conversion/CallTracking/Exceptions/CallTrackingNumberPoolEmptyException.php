<?php

declare(strict_types=1);

namespace App\Domain\Conversion\CallTracking\Exceptions;

use DomainException;

final class CallTrackingNumberPoolEmptyException extends DomainException
{
    public function __construct(
        public readonly bool $hadClickId,
        public readonly int $attributionWindowHours,
    ) {
        parent::__construct(
            'Tracking number pool is empty — all visitors will receive the default business number and call tracking is non-functional',
        );
    }

    /** @return array<string, scalar> */
    public function context(): array
    {
        return [
            'had_click_id' => $this->hadClickId,
            'attribution_window_hours' => $this->attributionWindowHours,
        ];
    }
}
