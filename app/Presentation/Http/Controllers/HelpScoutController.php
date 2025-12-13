<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers;

use App\Application\HelpScout\Queries\ConversationQueryParams;
use App\Application\HelpScout\Services\CachingHelpScoutService;
use App\Application\HelpScout\UseCases\GetEscalationsUseCase;
use App\Domain\CustomerService\ValueObjects\EscalationsConfig;
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
    ) {}

    /**
     * Get conversations assigned to the authenticated agent.
     */
    public function assigned(Request $request): JsonResponse
    {
        $params = self::createAssignedParams($this->resolveAgentId($request));

        return new JsonResponse([
            'data' => $this->service->getConversations($params),
        ]);
    }

    /**
     * Refresh assigned conversations cache and return fresh data.
     */
    public function refreshAssigned(Request $request): JsonResponse
    {
        $params = self::createAssignedParams($this->resolveAgentId($request));

        $this->service->invalidateConversations($params);

        return new JsonResponse([
            'data' => $this->service->getConversations($params),
        ]);
    }

    /**
     * Get tagged conversations (todos) for the authenticated agent.
     */
    public function todos(Request $request): JsonResponse
    {
        $params = self::createTodosParams(
            $this->resolveAgentId($request),
            $this->service->getEscalationsConfig(),
        );

        return new JsonResponse([
            'data' => $this->service->getConversations($params),
        ]);
    }

    /**
     * Refresh todos cache and return fresh data.
     */
    public function refreshTodos(Request $request): JsonResponse
    {
        $params = self::createTodosParams(
            $this->resolveAgentId($request),
            $this->service->getEscalationsConfig(),
        );

        $this->service->invalidateConversations($params);

        return new JsonResponse([
            'data' => $this->service->getConversations($params),
        ]);
    }

    /**
     * Get conversations tagged with negative feedback.
     */
    public function negativeReviews(): JsonResponse
    {
        $params = self::createNegativeReviewsParams();

        return new JsonResponse([
            'data' => $this->service->getConversations($params),
        ]);
    }

    /**
     * Refresh negative reviews cache and return fresh data.
     */
    public function refreshNegativeReviews(): JsonResponse
    {
        $params = self::createNegativeReviewsParams();

        $this->service->invalidateConversations($params);

        return new JsonResponse([
            'data' => $this->service->getConversations($params),
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
     * Create params for assigned conversations query.
     */
    private static function createAssignedParams(int $agentId): ConversationQueryParams
    {
        return ConversationQueryParams::assigned($agentId);
    }

    /**
     * Create params for todos query.
     */
    private static function createTodosParams(int $agentId, EscalationsConfig $config): ConversationQueryParams
    {
        return ConversationQueryParams::todos($agentId, $config->assignedTag);
    }

    /**
     * Create params for negative reviews query.
     */
    private static function createNegativeReviewsParams(): ConversationQueryParams
    {
        /** @var string $tag */
        $tag = \config('helpscout.negative_reviews_tag', '');

        return ConversationQueryParams::negativeReviews($tag);
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
