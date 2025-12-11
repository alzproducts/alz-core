<?php

declare(strict_types=1);

namespace App\Application\Contracts\HelpScout;

use App\Domain\CustomerService\ValueObjects\Conversation;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiResponseException;

/**
 * HelpScout Conversations API client contract.
 */
interface ConversationsClientInterface
{
    /**
     * Get conversations assigned to a specific agent.
     *
     * @return array<int, Conversation>
     *
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function getAssignedTo(int $agentId, string $status = 'active'): array;

    /**
     * Get conversations with a specific tag for an agent.
     *
     * @return array<int, Conversation>
     *
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function getWithTagForAgent(int $agentId, string $tag): array;

    /**
     * Get conversations with a specific tag (unfiltered by agent).
     *
     * @return array<int, Conversation>
     *
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function getWithTag(string $tag, string $status = 'active'): array;

    /**
     * Get conversations waiting since a specific time (for escalations).
     *
     * @param int $mailboxId Mailbox ID to query
     * @param string $waitingSinceQuery ISO 8601 datetime string
     *
     * @return array<int, Conversation>
     *
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function getWaitingSince(int $mailboxId, string $waitingSinceQuery): array;
}
