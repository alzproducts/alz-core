<?php

declare(strict_types=1);

namespace App\Domain\Conversion\CallTracking\Enums;

/**
 * Backing values match the `status` CHECK constraint on
 * `customer_service.call_tracking_actions`.
 */
enum CallTrackingActionStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed => true,
            self::Pending, self::Processing => false,
        };
    }
}
