<?php

declare(strict_types=1);

namespace App\Domain\CustomerService\ValueObjects;

use DateTimeImmutable;

/**
 * Snooze state of a conversation.
 */
final readonly class ConversationSnooze
{
    public function __construct(
        public DateTimeImmutable $snoozedUntil,
        public ?int $snoozedByUserId,
        public ?bool $unsnoozeOnCustomerReply = null,
    ) {}
}
