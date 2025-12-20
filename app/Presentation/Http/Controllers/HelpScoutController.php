<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controllers;

use App\Application\HelpScout\Queries\ConversationQueryParams;
use App\Application\HelpScout\Services\CachingHelpScoutService;
use App\Application\HelpScout\UseCases\GetConversationsUseCase;
use App\Application\HelpScout\UseCases\GetEscalationsUseCase;
use App\Domain\CustomerService\Exceptions\CustomerServiceAgentNotFoundException;
use App\Domain\Exceptions\ConfigurationNotFoundException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiResponseException;
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
     *
     * @throws CustomerServiceAgentNotFoundException When agent email not found in HelpScout
     * @throws ExternalServiceUnavailableException When HelpScout API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
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
     *
     * @throws CustomerServiceAgentNotFoundException When agent email not found in HelpScout
     * @throws ExternalServiceUnavailableException When HelpScout API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
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
     *
     * @throws CustomerServiceAgentNotFoundException When agent email not found in HelpScout
     * @throws ExternalServiceUnavailableException When HelpScout API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
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
     *
     * @throws CustomerServiceAgentNotFoundException When agent email not found in HelpScout
     * @throws ExternalServiceUnavailableException When HelpScout API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
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
     *
     * @throws ExternalServiceUnavailableException When HelpScout API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
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
     *
     * @throws ExternalServiceUnavailableException When HelpScout API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
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
     *
     * @throws ConfigurationNotFoundException When escalations config missing or disabled
     * @throws ExternalServiceUnavailableException When HelpScout API or database unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function escalations(GetEscalationsUseCase $useCase): JsonResponse
    {
        return new JsonResponse([
            'data' => $useCase->execute(),
        ]);
    }

    /**
     * Refresh escalations cache and return fresh data.
     *
     * @throws ConfigurationNotFoundException When escalations config missing or disabled
     * @throws ExternalServiceUnavailableException When HelpScout API or database unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function refreshEscalations(GetEscalationsUseCase $useCase): JsonResponse
    {
        return new JsonResponse([
            'data' => $useCase->execute(forceRefresh: true),
        ]);
    }

    /**
     * Get authenticated user's HelpScout profile.
     *
     * Returns agent details (name, email, role) for connection status display.
     *
     * @throws CustomerServiceAgentNotFoundException When agent email not found in HelpScout
     * @throws ExternalServiceUnavailableException When HelpScout API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function profile(Request $request): JsonResponse
    {
        /** @var string $email */
        $email = $request->input('auth_user_email');

        $agent = $this->service->getAgentProfile($email);

        return new JsonResponse([
            'data' => [
                'id' => $agent->id,
                'email' => $agent->email,
                'firstName' => $agent->firstName,
                'lastName' => $agent->lastName,
                'role' => $agent->role,
            ],
        ]);
    }

    /**
     * Resolve HelpScout agent ID from authenticated user email.
     *
     * @throws CustomerServiceAgentNotFoundException When agent email not found in HelpScout
     * @throws ExternalServiceUnavailableException When HelpScout API unavailable
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    private function resolveAgentId(Request $request): int
    {
        /** @var string $email */
        $email = $request->input('auth_user_email');

        return $this->service->getAgentProfile($email)->id;
    }
}
