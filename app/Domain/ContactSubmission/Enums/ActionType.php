<?php

declare(strict_types=1);

namespace App\Domain\ContactSubmission\Enums;

/**
 * Type of action to perform for a contact submission.
 *
 * Extensible for future integrations (Mixpanel, Slack notifications, etc.).
 */
enum ActionType: string
{
    case HelpScout = 'helpscout';

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::HelpScout => 'HelpScout',
        };
    }
}
