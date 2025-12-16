<?php

declare(strict_types=1);

namespace App\Application\HelpScout\UseCases;

use App\Application\HelpScout\Queries\ConversationQueryParams;
use App\Application\HelpScout\Services\CachingHelpScoutService;
use App\Application\HelpScout\Support\ConversationSorter;
use App\Domain\CustomerService\ValueObjects\Conversation;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiResponseException;

/**
 * Fetch conversations with default sorting.
 *
 * Handles simple conversation queries (assigned, todos, negative reviews)
 * that all need the same default sorting: status priority, then newest update.
 *
 * For queries requiring custom sorting or orchestration (e.g., escalations),
 * use a dedicated UseCase instead.
 */
final readonly class GetConversationsUseCase
{
    public function __construct(
        private CachingHelpScoutService $service,
    ) {}

    /**
     * Execute query and return sorted conversations.
     *
     * @param bool $forceRefresh Invalidate cache before fetching (for manual refresh)
     *
     * @return list<Conversation>
     *
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function execute(ConversationQueryParams $params, bool $forceRefresh = false): array
    {
        if ($forceRefresh) {
            $this->service->invalidateConversations($params);
        }

        $conversations = $this->service->getConversations($params);

        return ConversationSorter::byStatusAndDate($conversations);
    }
}
