<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Events;

use DateTimeImmutable;

/**
 * Domain event requesting an admin alert notification.
 *
 * Fired when something notable happens that may require human investigation.
 * Infrastructure listeners handle delivery (e.g. Slack, email, PagerDuty).
 *
 * `firedAt` is set automatically at construction time — it reflects when the
 * event was raised, not when the queued listener eventually processes it.
 */
final readonly class AdminAlertEvent
{
    public DateTimeImmutable $firedAt;

    /**
     * @param array<string, mixed> $context Key-value pairs shown as context in the notification
     */
    public function __construct(
        public string $title,
        public string $message,
        public array $context = [],
    ) {
        $this->firedAt = new DateTimeImmutable();
    }
}
