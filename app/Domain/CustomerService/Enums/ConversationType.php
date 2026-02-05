<?php

declare(strict_types=1);

namespace App\Domain\CustomerService\Enums;

/**
 * Communication channel types for customer service conversations.
 *
 * Represents how a customer initiated contact with support,
 * independent of any specific helpdesk tool implementation.
 */
enum ConversationType: string
{
    /**
     * Email conversation - standard email-based support ticket.
     * Most common type for contact form submissions.
     */
    case Email = 'email';

    /**
     * Phone conversation - record of a phone call.
     * Used when logging support interactions from phone calls.
     */
    case Phone = 'phone';

    /**
     * Chat conversation - real-time chat session.
     * Used for live chat support interactions.
     */
    case Chat = 'chat';
}
