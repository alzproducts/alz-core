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
        public ?DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $userUpdatedAt,
        public ?DateTimeImmutable $customerWaitingSince,
        public ?ConversationSnooze $snooze,
        public array $tags,
        public ?ConversationCustomer $customer,
        public ?ConversationAssignee $assignee,
        public ?string $mailboxName = null,
    ) {
        Assert::greaterThan($id, 0, 'Conversation ID must be positive');
        Assert::greaterThan($number, 0, 'Conversation number must be positive');
        Assert::notEmpty($subject, 'Conversation subject cannot be empty');
    }

    /**
     * Create a copy with the mailbox name set.
     *
     * Used by Application layer to enrich conversations with mailbox metadata.
     */
    public function withMailboxName(?string $name): self
    {
        return new self(
            id: $this->id,
            number: $this->number,
            subject: $this->subject,
            status: $this->status,
            mailboxId: $this->mailboxId,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
            userUpdatedAt: $this->userUpdatedAt,
            customerWaitingSince: $this->customerWaitingSince,
            snooze: $this->snooze,
            tags: $this->tags,
            customer: $this->customer,
            assignee: $this->assignee,
            mailboxName: $name,
        );
    }
}
