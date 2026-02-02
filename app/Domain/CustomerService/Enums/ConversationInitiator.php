<?php

declare(strict_types=1);

namespace App\Domain\CustomerService\Enums;

/**
 * Who initiated a customer service conversation.
 *
 * Determines the thread type and workflow in helpdesk systems:
 * - Customer: Inbound - customer contacted us (e.g., contact form)
 * - Agent: Outbound - business proactively contacted customer
 */
enum ConversationInitiator: string
{
    /**
     * Customer-initiated conversation (inbound).
     *
     * Customer reached out via contact form, email, phone, etc.
     * The first message comes from the customer.
     */
    case Customer = 'customer';

    /**
     * Agent-initiated conversation (outbound).
     *
     * Business proactively contacts customer.
     * The first message comes from an agent/support team.
     */
    case Agent = 'agent';
}
