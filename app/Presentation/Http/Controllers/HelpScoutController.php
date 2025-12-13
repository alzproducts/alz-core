<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers;

use App\Application\HelpScout\Queries\ConversationQueryParams;
use App\Application\HelpScout\Services\CachingHelpScoutService;
use App\Application\HelpScout\UseCases\GetConversationsUseCase;
use App\Application\HelpScout\UseCases\GetEscalationsUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HelpScout conversation endpoints for dashboard widgets.
 *
 * Provides conversation queries with caching and refresh capability.
 * All endpoints require Supabase JWT auth; agent ID resolved from email.
 *
 * Endpoints:
 * - assigned: Conversations assigned to the authenticated agent
 * - todos: Tagged conversations requiring agent action
 * - negative-reviews: Conversations with negative feedback tag
 * - escalations: Late and manually assigned conversations across mailboxes
 */
final readonly class HelpScoutController
{
    public function __construct(
        private CachingHelpScoutService $service,
        private GetConversationsUseCase $getConversations,
    ) {}

    /**
     * Get conversations assigned to the authenticated agent.
     */
    public function assigned(Request $request): JsonResponse
    {
        $params = ConversationQueryParams::assigned($this->resolveAgentId($request));

        return new JsonResponse([
            'data' => $this->getConversations->execute($params),
        ]);
    }

    /**
     * Refresh assigned conversations cache and return fresh data.
     */
    public function refreshAssigned(Request $request): JsonResponse
    {
        $params = ConversationQueryParams::assigned($this->resolveAgentId($request));

        return new JsonResponse([
            'data' => $this->getConversations->execute($params, forceRefresh: true),
        ]);
    }

    /**
     * Get tagged conversations (todos) for the authenticated agent.
     */
    public function todos(Request $request): JsonResponse
    {
        $params = ConversationQueryParams::todos($this->resolveAgentId($request));

        return new JsonResponse([
            'data' => $this->getConversations->execute($params),
        ]);
    }

    /**
     * Refresh todos cache and return fresh data.
     */
    public function refreshTodos(Request $request): JsonResponse
    {
        $params = ConversationQueryParams::todos($this->resolveAgentId($request));

        return new JsonResponse([
            'data' => $this->getConversations->execute($params, forceRefresh: true),
        ]);
    }

    /**
     * Get conversations tagged with negative feedback.
     */
    public function negativeReviews(): JsonResponse
    {
        $params = ConversationQueryParams::negativeReviews();

        return new JsonResponse([
            'data' => $this->getConversations->execute($params),
        ]);
    }

    /**
     * Refresh negative reviews cache and return fresh data.
     */
    public function refreshNegativeReviews(): JsonResponse
    {
        $params = ConversationQueryParams::negativeReviews();

        return new JsonResponse([
            'data' => $this->getConversations->execute($params, forceRefresh: true),
        ]);
    }

    /**
     * Get escalated conversations across mailboxes.
     *
     * Aggregates late priority, late standard, and manually assigned
     * conversations from Support and Purchase Orders mailboxes.
     */
    public function escalations(GetEscalationsUseCase $useCase): JsonResponse
    {
        return new JsonResponse([
            'data' => $useCase->execute(),
        ]);
    }

    /**
     * Refresh escalations cache and return fresh data.
     */
    public function refreshEscalations(GetEscalationsUseCase $useCase): JsonResponse
    {
        return new JsonResponse([
            'data' => $useCase->execute(forceRefresh: true),
        ]);
    }

    /**
     * Resolve HelpScout agent ID from authenticated user email.
     */
    private function resolveAgentId(Request $request): int
    {
        /** @var string $email */
        $email = $request->input('auth_user_email');

        return $this->service->resolveAgentId($email);
    }
}
