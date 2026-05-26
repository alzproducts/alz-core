<?php

declare(strict_types=1);

namespace App\Application\HelpScout\Commands;

use App\Domain\CustomerService\Enums\ConversationStatus;
use App\Domain\CustomerService\Enums\ConversationType;
use App\Domain\CustomerService\Enums\Mailbox;
use App\Domain\CustomerService\ValueObjects\Tag;
use Webmozart\Assert\Assert;

/**
 * Command to create a customer-initiated conversation.
 *
 * Used when a customer contacts support (e.g., contact form, inbound email).
 * The conversation is created with the customer as the thread author.
 *
 * Applies basic normalization:
 * - Email is lowercased and trimmed (when provided)
 * - Name and subject are trimmed
 *
 * @template-pattern Application Command
 */
final readonly class CreateCustomerConversationCommand
{
    public ?string $email;
    public string $name;
    public string $subject;
    public ?string $phone;

    /**
     * @param string|null $email Customer email address (required for email/chat, null for phone)
     * @param string $name Customer full name
     * @param string $subject Conversation subject line
     * @param string $body Message body (HTML — caller must escape user content)
     * @param Mailbox $mailbox Target mailbox
     * @param ConversationType $type Conversation type (email, phone, chat)
     * @param ConversationStatus $status Initial conversation status
     * @param string|null $phone Customer phone number (required for phone, optional otherwise)
     * @param list<Tag> $tags Tags to apply to the conversation
     */
    public function __construct(
        ?string $email,
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
        Assert::true($email !== null || $phone !== null, 'email or phone required');

        $this->email = $email !== null ? \mb_strtolower(\mb_trim($email)) : null;
        $this->name = \mb_trim($name);
        $this->subject = \mb_trim($subject);
        $this->phone = $phone !== null ? \mb_trim($phone) : null;
    }
}
