<?php

declare(strict_types=1);

namespace App\Domain\Conversion\CallTracking\Enums;

/**
 * Backing values match the `call_status` CHECK constraint on
 * `customer_service.call_tracking_calls`.
 */
enum CallStatus: string
{
    case Initiated = 'initiated';
}
