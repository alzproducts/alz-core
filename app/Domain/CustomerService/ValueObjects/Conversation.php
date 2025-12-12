<?php

declare(strict_types=1);

namespace App\Domain\CustomerService\ValueObjects;

use DateTimeImmutable;
use Webmozart\Assert\Assert;

/**
 * A customer service conversation.
 *
 * Represents a support ticket/thread with its current state.
 * ID included for cross-referencing and frontend linking.
 */
final readonly class Conversation
{
    /**
     * @param list<ConversationTag> $tags
     */
    public function __construct(
        public int $id,
        public int $number,
        public string $subject,
        public string $status,
        public int $mailboxId,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $customerWaitingSince,
        public ?ConversationSnooze $snooze,
        public array $tags,
        public ?ConversationCustomer $customer,
        public ?ConversationAssignee $assignee,
    ) {
        Assert::greaterThan($id, 0, 'Conversation ID must be positive');
        Assert::greaterThan($number, 0, 'Conversation number must be positive');
        Assert::notEmpty($subject, 'Conversation subject cannot be empty');
    }
}
