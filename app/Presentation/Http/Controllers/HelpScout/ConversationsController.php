<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers\HelpScout;

use App\Application\HelpScout\Queries\ConversationQueryParams;
use App\Application\HelpScout\Services\CachingHelpScoutService;
use App\Application\HelpScout\UseCases\GetConversationsUseCase;
use App\Application\HelpScout\UseCases\GetEscalationsUseCase;
use App\Domain\Access\ValueObjects\AuthenticatedUser;
use App\Domain\CustomerService\Exceptions\CustomerServiceAgentNotFoundException;
use App\Domain\Exceptions\ConfigurationNotFoundException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiResponseException;
use App\Presentation\Http\HelpScout\Resources\ConversationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HelpScout conversation endpoints for dashboard widgets.
 *
 * Provides conversation queries with caching and refresh capability.
 * All endpoints require Supabase JWT auth; agent ID resolved from email.
 *
 * Refresh behavior controlled by DetectRefreshMiddleware:
 * - GET request → cached data
 * - POST request → invalidate cache + fetch fresh
 */
final readonly class ConversationsController
{
    public function __construct(
        private CachingHelpScoutService $service,
        private GetConversationsUseCase $getConversations,
    ) {}

    /**
     * Get conversations assigned to the authenticated agent.
     *
     * @throws CustomerServiceAgentNotFoundException When agent email not found in HelpScout
     * @throws ExternalServiceUnavailableException When HelpScout API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function assigned(Request $request, AuthenticatedUser $user): JsonResponse
    {
        $forceRefresh = (bool) $request->attributes->get('forceRefresh', false);
        $params = ConversationQueryParams::assigned($this->resolveAgentId($user));
        $conversations = $this->getConversations->execute($params, forceRefresh: $forceRefresh);

        return new JsonResponse([
            'data' => ConversationResource::collection($conversations),
        ]);
    }

    /**
     * Get tagged conversations (todos) for the authenticated agent.
     *
     * @throws CustomerServiceAgentNotFoundException When agent email not found in HelpScout
     * @throws ExternalServiceUnavailableException When HelpScout API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function todos(Request $request, AuthenticatedUser $user): JsonResponse
    {
        $forceRefresh = (bool) $request->attributes->get('forceRefresh', false);
        $params = ConversationQueryParams::todos($this->resolveAgentId($user));
        $conversations = $this->getConversations->execute($params, forceRefresh: $forceRefresh);

        return new JsonResponse([
            'data' => ConversationResource::collection($conversations),
        ]);
    }

    /**
     * Get conversations tagged with negative feedback.
     *
     * @throws ExternalServiceUnavailableException When HelpScout API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function negativeReviews(Request $request): JsonResponse
    {
        $forceRefresh = (bool) $request->attributes->get('forceRefresh', false);
        $params = ConversationQueryParams::negativeReviews();
        $conversations = $this->getConversations->execute($params, forceRefresh: $forceRefresh);

        return new JsonResponse([
            'data' => ConversationResource::collection($conversations),
        ]);
    }

    /**
     * Get escalated conversations across mailboxes.
     *
     * Aggregates late priority, late standard, and manually assigned
     * conversations from Support and Purchase Orders mailboxes.
     *
     * @throws ConfigurationNotFoundException When escalations config missing or disabled
     * @throws ExternalServiceUnavailableException When HelpScout API or database unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function escalations(Request $request, GetEscalationsUseCase $useCase): JsonResponse
    {
        $forceRefresh = (bool) $request->attributes->get('forceRefresh', false);
        $conversations = $useCase->execute(forceRefresh: $forceRefresh);

        return new JsonResponse([
            'data' => ConversationResource::collection($conversations),
        ]);
    }

    /**
     * Resolve HelpScout agent ID from authenticated user.
     *
     * @throws CustomerServiceAgentNotFoundException When agent email not found in HelpScout
     * @throws ExternalServiceUnavailableException When HelpScout API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    private function resolveAgentId(AuthenticatedUser $user): int
    {
        return $this->service->getAgentProfile($user->email)->id;
    }
}
