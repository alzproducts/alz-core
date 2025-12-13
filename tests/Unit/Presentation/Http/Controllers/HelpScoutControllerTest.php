<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation\Http\Controllers;

use App\Application\HelpScout\Queries\ConversationQueryParams;
use App\Application\HelpScout\Services\CachingHelpScoutService;
use App\Application\HelpScout\UseCases\GetConversationsUseCase;
use App\Application\HelpScout\UseCases\GetEscalationsUseCase;
use App\Domain\CustomerService\ValueObjects\Conversation;
use App\Presentation\Http\Controllers\HelpScoutController;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(HelpScoutController::class)]
final class HelpScoutControllerTest extends TestCase
{
    private CachingHelpScoutService&MockInterface $mockService;

    private GetConversationsUseCase&MockInterface $mockGetConversations;

    private HelpScoutController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockService = Mockery::mock(CachingHelpScoutService::class);
        $this->mockGetConversations = Mockery::mock(GetConversationsUseCase::class);

        $this->controller = new HelpScoutController(
            $this->mockService,
            $this->mockGetConversations,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | assigned() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function assigned_resolves_agent_id_from_request(): void
    {
        $request = $this->createRequestWithEmail('agent@example.com');

        $this->mockService->expects('resolveAgentId')
            ->with('agent@example.com')
            ->once()
            ->andReturn(12345);

        $this->mockGetConversations->expects('execute')
            ->andReturn([]);

        $this->controller->assigned($request);
    }

    #[Test]
    public function assigned_delegates_to_use_case_with_assigned_params(): void
    {
        $request = $this->createRequestWithEmail('agent@example.com');

        $this->mockService->expects('resolveAgentId')
            ->andReturn(12345);

        $capturedParams = null;
        $this->mockGetConversations->expects('execute')
            ->once()
            ->andReturnUsing(static function (ConversationQueryParams $params) use (&$capturedParams): array {
                $capturedParams = $params;

                return [];
            });

        $this->controller->assigned($request);

        $this->assertStringContainsString('assigned', $capturedParams->getCacheKey());
        $this->assertStringContainsString('agent=12345', $capturedParams->getCacheKey());
    }

    #[Test]
    public function assigned_returns_json_response_with_data(): void
    {
        $request = $this->createRequestWithEmail('agent@example.com');
        $conversations = [$this->createConversation(1)];

        $this->mockService->expects('resolveAgentId')
            ->andReturn(12345);

        $this->mockGetConversations->expects('execute')
            ->andReturn($conversations);

        $response = $this->controller->assigned($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $data = $response->getData(assoc: true);
        $this->assertArrayHasKey('data', $data);
    }

    /*
    |--------------------------------------------------------------------------
    | refreshAssigned() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function refresh_assigned_passes_force_refresh_true(): void
    {
        $request = $this->createRequestWithEmail('agent@example.com');

        $this->mockService->expects('resolveAgentId')
            ->andReturn(12345);

        $this->mockGetConversations->expects('execute')
            ->withArgs(static fn(ConversationQueryParams $params, bool $forceRefresh): bool => $forceRefresh === true)
            ->once()
            ->andReturn([]);

        $this->controller->refreshAssigned($request);
    }

    /*
    |--------------------------------------------------------------------------
    | todos() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function todos_resolves_agent_id_from_request(): void
    {
        $request = $this->createRequestWithEmail('agent@example.com');

        $this->mockService->expects('resolveAgentId')
            ->with('agent@example.com')
            ->once()
            ->andReturn(12345);

        $this->mockGetConversations->expects('execute')
            ->andReturn([]);

        $this->controller->todos($request);
    }

    #[Test]
    public function todos_delegates_to_use_case_with_todos_params(): void
    {
        $request = $this->createRequestWithEmail('agent@example.com');

        $this->mockService->expects('resolveAgentId')
            ->andReturn(12345);

        $capturedParams = null;
        $this->mockGetConversations->expects('execute')
            ->once()
            ->andReturnUsing(static function (ConversationQueryParams $params) use (&$capturedParams): array {
                $capturedParams = $params;

                return [];
            });

        $this->controller->todos($request);

        $this->assertStringContainsString('todos', $capturedParams->getCacheKey());
        $this->assertStringContainsString('agent=12345', $capturedParams->getCacheKey());
    }

    #[Test]
    public function todos_returns_json_response_with_data(): void
    {
        $request = $this->createRequestWithEmail('agent@example.com');

        $this->mockService->expects('resolveAgentId')
            ->andReturn(12345);

        $this->mockGetConversations->expects('execute')
            ->andReturn([]);

        $response = $this->controller->todos($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $data = $response->getData(assoc: true);
        $this->assertArrayHasKey('data', $data);
    }

    /*
    |--------------------------------------------------------------------------
    | refreshTodos() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function refresh_todos_passes_force_refresh_true(): void
    {
        $request = $this->createRequestWithEmail('agent@example.com');

        $this->mockService->expects('resolveAgentId')
            ->andReturn(12345);

        $this->mockGetConversations->expects('execute')
            ->withArgs(static fn(ConversationQueryParams $params, bool $forceRefresh): bool => $forceRefresh === true)
            ->once()
            ->andReturn([]);

        $this->controller->refreshTodos($request);
    }

    /*
    |--------------------------------------------------------------------------
    | negativeReviews() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function negative_reviews_does_not_require_agent_id(): void
    {
        $this->mockService->shouldNotReceive('resolveAgentId');

        $this->mockGetConversations->expects('execute')
            ->andReturn([]);

        $this->controller->negativeReviews();
    }

    #[Test]
    public function negative_reviews_delegates_to_use_case_with_negative_reviews_params(): void
    {
        $this->mockGetConversations->expects('execute')
            ->withArgs(static fn(ConversationQueryParams $params): bool => \str_contains($params->getCacheKey(), 'negative-reviews'))
            ->once()
            ->andReturn([]);

        $this->controller->negativeReviews();
    }

    #[Test]
    public function negative_reviews_returns_json_response(): void
    {
        $this->mockGetConversations->expects('execute')
            ->andReturn([]);

        $response = $this->controller->negativeReviews();

        $this->assertInstanceOf(JsonResponse::class, $response);
        $data = $response->getData(assoc: true);
        $this->assertArrayHasKey('data', $data);
    }

    /*
    |--------------------------------------------------------------------------
    | refreshNegativeReviews() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function refresh_negative_reviews_passes_force_refresh_true(): void
    {
        $this->mockGetConversations->expects('execute')
            ->withArgs(static fn(ConversationQueryParams $params, bool $forceRefresh): bool => $forceRefresh === true)
            ->once()
            ->andReturn([]);

        $this->controller->refreshNegativeReviews();
    }

    /*
    |--------------------------------------------------------------------------
    | escalations() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function escalations_delegates_to_injected_use_case(): void
    {
        $mockUseCase = Mockery::mock(GetEscalationsUseCase::class);
        $mockUseCase->expects('execute')
            ->withNoArgs() // forceRefresh defaults to false
            ->once()
            ->andReturn([]);

        $response = $this->controller->escalations($mockUseCase);

        $this->assertInstanceOf(JsonResponse::class, $response);
    }

    #[Test]
    public function escalations_returns_json_response_with_data(): void
    {
        $conversations = [$this->createConversation(1)];

        $mockUseCase = Mockery::mock(GetEscalationsUseCase::class);
        $mockUseCase->expects('execute')
            ->andReturn($conversations);

        $response = $this->controller->escalations($mockUseCase);

        $data = $response->getData(assoc: true);
        $this->assertArrayHasKey('data', $data);
    }

    /*
    |--------------------------------------------------------------------------
    | refreshEscalations() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function refresh_escalations_passes_force_refresh_true(): void
    {
        $mockUseCase = Mockery::mock(GetEscalationsUseCase::class);
        $mockUseCase->expects('execute')
            ->with(true) // forceRefresh
            ->once()
            ->andReturn([]);

        $this->controller->refreshEscalations($mockUseCase);
    }

    /*
    |--------------------------------------------------------------------------
    | Test Helpers
    |--------------------------------------------------------------------------
    */

    private function createRequestWithEmail(string $email): Request
    {
        $request = new Request();
        $request->merge(['auth_user_email' => $email]);

        return $request;
    }

    private function createConversation(int $id): Conversation
    {
        return new Conversation(
            id: $id,
            number: 1000 + $id,
            subject: "Test conversation {$id}",
            status: 'active',
            mailboxId: 100,
            createdAt: new DateTimeImmutable('2024-12-14'),
            updatedAt: null,
            userUpdatedAt: null,
            customerWaitingSince: null,
            snooze: null,
            tags: [],
            customer: null,
            assignee: null,
        );
    }
}
