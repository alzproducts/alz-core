<?php

declare(strict_types=1);

namespace App\Domain\ContactForm\Enums;

/**
 * Processing status for a contact submission action.
 *
 * These values match the CHECK constraint in customer_service.contact_submission_actions.
 */
enum ActionStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }

    /**
     * Determines if this status represents a terminal state.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed => true,
            self::Pending, self::Processing => false,
        };
    }
}
