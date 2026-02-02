<?php

declare(strict_types=1);

namespace App\Application\HelpScout\Commands;

use App\Domain\CustomerService\Enums\ConversationStatus;
use App\Domain\CustomerService\Enums\ConversationType;
use App\Domain\CustomerService\Enums\Mailbox;
use App\Domain\CustomerService\ValueObjects\Tag;

/**
 * Command to create a conversation in an external helpdesk system.
 *
 * Generic command for creating conversations in any mailbox.
 * The caller builds the subject and body from their domain objects;
 * this command is transport-agnostic.
 *
 * Applies basic normalization:
 * - Email is lowercased and trimmed
 * - Name and subject are trimmed
 *
 * @template-pattern Application Command
 */
final readonly class CreateConversationCommand
{
    public string $email;
    public string $name;
    public string $subject;

    /**
     * @param string $email Customer email address
     * @param string $name Customer full name
     * @param string $subject Conversation subject line
     * @param string $body Message body (plain text, will be HTML-escaped by SDK)
     * @param Mailbox $mailbox Target mailbox
     * @param ConversationType $type Conversation type (email, phone, chat)
     * @param ConversationStatus $status Initial conversation status
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
        /** @var list<Tag> */
        public array $tags = [],
    ) {
        $this->email = \mb_strtolower(\mb_trim($email));
        $this->name = \mb_trim($name);
        $this->subject = \mb_trim($subject);
    }
}
