<?php

declare(strict_types=1);

namespace App\Domain\ContactSubmission\ValueObjects;

/**
 * Consent Mode v2 status at time of form submission.
 *
 * Captured as an immutable snapshot for compliance audit trail.
 */
final readonly class ConsentStatus
{
    public function __construct(
        public bool $marketing,
        public bool $statistics,
        public bool $preferences,
        public bool $hasResponded,
    ) {}

    /**
     * Create with all consents denied (default state).
     */
    public static function denied(): self
    {
        return new self(
            marketing: false,
            statistics: false,
            preferences: false,
            hasResponded: false,
        );
    }

    /**
     * Whether the user has given any consent.
     */
    public function hasAnyConsent(): bool
    {
        return $this->marketing || $this->statistics || $this->preferences;
    }
}
