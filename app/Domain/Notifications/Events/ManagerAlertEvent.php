<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Events;

use DateTimeImmutable;

/**
 * Domain event requesting a manager-level alert notification.
 *
 * Fired for business-notable events that a broader team should see
 * (e.g. order deletions, webhook health issues, unusual data states).
 * Distinct from AdminAlertEvent which targets infrastructure/dev alerts.
 *
 * `firedAt` is set automatically at construction time — it reflects when the
 * event was raised, not when the queued listener eventually processes it.
 */
final readonly class ManagerAlertEvent
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
