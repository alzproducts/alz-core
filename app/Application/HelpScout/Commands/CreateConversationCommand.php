<?php

declare(strict_types=1);

namespace App\Application\HelpScout\Commands;

use App\Domain\CustomerService\Enums\ConversationStatus;
use App\Domain\CustomerService\Enums\ConversationType;
use App\Domain\CustomerService\Enums\Mailbox;

/**
 * Command to create a HelpScout conversation.
 *
 * Generic command for creating conversations in any mailbox.
 * The caller builds the subject and body from their domain objects;
 * this command is transport-agnostic.
 *
 * Applies basic normalization:
 * - Tags are lowercased (HelpScout convention)
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

    /** @var list<string> */
    public array $tags;

    /**
     * @param string $email Customer email address
     * @param string $name Customer full name
     * @param string $subject Conversation subject line
     * @param string $body Message body (plain text, will be HTML-escaped by SDK)
     * @param Mailbox $mailbox Target mailbox
     * @param ConversationType $type Conversation type (email, phone, chat)
     * @param ConversationStatus $status Initial conversation status
     * @param list<string> $tags HelpScout tags to apply (will be lowercased)
     */
    public function __construct(
        string $email,
        string $name,
        string $subject,
        public string $body,
        public Mailbox $mailbox,
        public ConversationType $type,
        public ConversationStatus $status,
        array $tags = [],
    ) {
        $this->email = \mb_strtolower(\mb_trim($email));
        $this->name = \mb_trim($name);
        $this->subject = \mb_trim($subject);
        $this->tags = \array_map(static fn(string $tag): string => \mb_strtolower(\mb_trim($tag)), $tags);
    }
}
