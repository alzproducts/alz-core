<?php

declare(strict_types=1);

namespace App\Application\Notifications\DTOs;

use App\Application\Contracts\ChatNotificationInterface;
use DateTimeImmutable;

/**
 * Parameter object for {@see ChatNotificationInterface::sendAlert()}.
 *
 * Groups the alert payload (title, body, context fields, and the originating
 * event timestamp). The destination audience is passed separately.
 */
final readonly class AlertNotificationDataDTO
{
    /**
     * @param array<string, mixed> $context Key-value pairs shown as context in the notification
     */
    public function __construct(
        public string $title,
        public string $message,
        public array $context,
        public DateTimeImmutable $firedAt,
    ) {}
}
