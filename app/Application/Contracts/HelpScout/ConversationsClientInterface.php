<?php

declare(strict_types=1);

namespace App\Application\Contracts\HelpScout;

use App\Application\HelpScout\Queries\ConversationQueryParams;
use App\Domain\CustomerService\ValueObjects\Conversation;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;

/**
 * HelpScout Conversations read operations client contract.
 *
 * Separate from write operations because:
 * - Reads use direct HTTP (SDK hydration drops fields)
 * - Writes use SDK (clean serialization)
 *
 * @see ConversationWriteClientInterface for write operations
 */
interface ConversationsClientInterface
{
    /**
     * Get conversations based on query parameters.
     *
     * Builds HelpScout API query from params and returns Domain value objects.
     *
     * @return list<Conversation>
     *
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function getConversations(ConversationQueryParams $params): array;

    /**
     * Get conversations for multiple queries in parallel.
     *
     * Executes multiple conversation queries concurrently for performance.
     * Returns nested arrays to preserve query-result association for caching.
     *
     * @param list<ConversationQueryParams> $queries
     *
     * @return list<list<Conversation>> Results indexed same as input queries
     *
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function getConversationsBatch(array $queries): array;
}
