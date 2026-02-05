<?php

declare(strict_types=1);

namespace App\Domain\CustomerService\Enums;

/**
 * Workflow states for customer service conversations.
 *
 * Determines the workflow state of a support conversation,
 * independent of any specific helpdesk tool implementation.
 */
enum ConversationStatus: string
{
    /**
     * Active conversation - requires attention from support team.
     * New conversations should typically start as active.
     */
    case Active = 'active';

    /**
     * Pending conversation - waiting for customer response.
     * Use when support has replied and is awaiting customer feedback.
     */
    case Pending = 'pending';

    /**
     * Closed conversation - resolved and complete.
     * Use when the issue has been resolved.
     */
    case Closed = 'closed';
}
