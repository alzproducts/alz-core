<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Clients;

use App\Domain\Exceptions\InvalidApiResponseException;
use App\Infrastructure\HelpScout\HelpScoutHttpTransport;
use App\Infrastructure\HelpScout\HelpScoutResponseParser;
use App\Infrastructure\HelpScout\Responses\Conversation;
use App\Infrastructure\HelpScout\Responses\ConversationsResponse;
use App\Infrastructure\HelpScout\Responses\Page;

/**
 * HelpScout Conversations API Client.
 *
 * Handles conversation queries with various filters:
 * - By assignee (user's inbox)
 * - By tag (to-do, negative reviews, etc.)
 * - By waiting time (escalations)
 *
 * @see https://developer.helpscout.com/mailbox-api/endpoints/conversations/
 */
final readonly class ConversationsClient
{
    use HelpScoutResponseParser;

    private const string ENDPOINT = '/conversations';

    public function __construct(
        private HelpScoutHttpTransport $transport,
    ) {}

    /**
     * Search conversations with custom query parameters.
     *
     * @param array<string, mixed> $params Query parameters for the HelpScout conversations API
     *
     * @see https://developer.helpscout.com/mailbox-api/endpoints/conversations/list/
     */
    public function search(array $params = []): ConversationsResponse
    {
        $response = $this->transport->get(self::ENDPOINT, $params);

        return $this->parseConversationsResponse($response->json());
    }

    /**
     * Get conversations assigned to a specific user.
     *
     * @param int $userId HelpScout user ID
     * @param string $status Conversation status filter (active, pending, closed, spam, etc.)
     */
    public function getAssignedTo(int $userId, string $status = 'active'): ConversationsResponse
    {
        return $this->search([
            'assigned' => $userId,
            'status' => $status,
        ]);
    }

    /**
     * Get conversations with a specific tag assigned to a user.
     *
     * @param int $userId HelpScout user ID
     * @param string $tag Tag name to filter by
     */
    public function getWithTagForUser(int $userId, string $tag): ConversationsResponse
    {
        return $this->search([
            'assigned' => $userId,
            'tag' => $tag,
            'status' => 'active',
        ]);
    }

    /**
     * Get conversations with a specific tag (unfiltered by user).
     *
     * @param string $tag Tag name to filter by
     * @param string $status Conversation status filter
     */
    public function getWithTag(string $tag, string $status = 'active'): ConversationsResponse
    {
        return $this->search(\compact('tag', 'status'));
    }

    /**
     * Get conversations in a mailbox waiting since a specific time.
     *
     * Used for escalation queries (late responses).
     *
     * @param int $mailboxId Mailbox ID to query
     * @param string $waitingSinceQuery ISO 8601 datetime string for customerWaitingSince filter
     */
    public function getWaitingSince(int $mailboxId, string $waitingSinceQuery): ConversationsResponse
    {
        return $this->search([
            'mailbox' => $mailboxId,
            'status' => 'active',
            'query' => "(customerWaitingSince.time:[* TO {$waitingSinceQuery}])",
        ]);
    }

    /**
     * Parse HelpScout conversations response with embedded structure.
     *
     * HelpScout returns conversations in `_embedded.conversations` with pagination in `page`.
     */
    private function parseConversationsResponse(mixed $data): ConversationsResponse
    {
        $this->validateArrayResponse($data, 'conversations');

        /** @var array<mixed> $data */
        $embedded = $this->extractEmbedded($data, 'conversations');

        /** @var array<string, mixed>|null $pageData */
        $pageData = $data['page'] ?? null;

        if ($pageData === null) {
            self::logParsingFailure('Missing page in response', $data);

            throw new InvalidApiResponseException(
                serviceName: self::SERVICE_NAME,
                message: 'Missing page in response',
            );
        }

        /** @var array<Conversation> $conversations */
        $conversations = $this->parseArrayResponse($embedded, Conversation::class)->all();
        $page = Page::from($pageData);

        return new ConversationsResponse(
            conversations: $conversations,
            page: $page,
        );
    }
}
