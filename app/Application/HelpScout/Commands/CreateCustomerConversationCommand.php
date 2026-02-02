<?php

declare(strict_types=1);

namespace App\Application\HelpScout\Commands;

use App\Domain\CustomerService\Enums\ConversationStatus;
use App\Domain\CustomerService\Enums\ConversationType;
use App\Domain\CustomerService\Enums\Mailbox;
use App\Domain\CustomerService\ValueObjects\Tag;

/**
 * Command to create a customer-initiated conversation.
 *
 * Used when a customer contacts support (e.g., contact form, inbound email).
 * The conversation is created with the customer as the thread author.
 *
 * Applies basic normalization:
 * - Email is lowercased and trimmed
 * - Name and subject are trimmed
 *
 * @template-pattern Application Command
 */
final readonly class CreateCustomerConversationCommand
{
    public string $email;
    public string $name;
    public string $subject;
    public ?string $phone;

    /**
     * @param string $email Customer email address
     * @param string $name Customer full name
     * @param string $subject Conversation subject line
     * @param string $body Message body (plain text, will be HTML-escaped by SDK)
     * @param Mailbox $mailbox Target mailbox
     * @param ConversationType $type Conversation type (email, phone, chat)
     * @param ConversationStatus $status Initial conversation status
     * @param string|null $phone Customer phone number (optional)
     * @param list<Tag> $tags Tags to apply to the conversation
     */
    public function __construct(
        string $email,
        string $name,
        string $subject,
        public string $body,
        public Mailbox $mailbox,
        public ConversationType $type,
        public ConversationStatus $status,
        ?string $phone = null,
        /** @var list<Tag> */
        public array $tags = [],
    ) {
        $this->email = \mb_strtolower(\mb_trim($email));
        $this->name = \mb_trim($name);
        $this->subject = \mb_trim($subject);
        $this->phone = $phone !== null ? \mb_trim($phone) : null;
    }
}
