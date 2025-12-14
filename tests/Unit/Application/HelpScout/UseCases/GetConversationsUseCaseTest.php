<?php

declare(strict_types=1);

namespace Tests\Unit\Application\HelpScout\UseCases;

use App\Application\HelpScout\Queries\ConversationQueryParams;
use App\Application\HelpScout\Services\CachingHelpScoutService;
use App\Application\HelpScout\UseCases\GetConversationsUseCase;
use App\Domain\CustomerService\ValueObjects\Conversation;
use DateTimeImmutable;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(GetConversationsUseCase::class)]
final class GetConversationsUseCaseTest extends TestCase
{
    private CachingHelpScoutService&MockInterface $mockService;

    private GetConversationsUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockService = Mockery::mock(CachingHelpScoutService::class);
        $this->useCase = new GetConversationsUseCase($this->mockService);
    }

    /*
    |--------------------------------------------------------------------------
    | execute() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_returns_conversations_sorted_by_status_and_date(): void
    {
        $closedConversation = $this->createConversation(1, 'closed');
        $activeConversation = $this->createConversation(2, 'active');
        $pendingConversation = $this->createConversation(3, 'pending');

        $this->mockService->expects('getConversations')
            ->once()
            ->andReturn([$closedConversation, $activeConversation, $pendingConversation]);

        $params = ConversationQueryParams::assigned(agentId: 12345);
        $result = $this->useCase->execute($params);

        // Should be sorted: active, pending, closed
        $this->assertSame(2, $result[0]->id);
        $this->assertSame(3, $result[1]->id);
        $this->assertSame(1, $result[2]->id);
    }

    #[Test]
    public function execute_returns_empty_array_when_no_conversations(): void
    {
        $this->mockService->expects('getConversations')
            ->once()
            ->andReturn([]);

        $params = ConversationQueryParams::assigned(agentId: 12345);
        $result = $this->useCase->execute($params);

        $this->assertSame([], $result);
    }

    #[Test]
    public function execute_passes_params_to_service(): void
    {
        $params = ConversationQueryParams::todos(agentId: 99999);

        $this->mockService->expects('getConversations')
            ->with($params)
            ->once()
            ->andReturn([]);

        $this->useCase->execute($params);
    }

    #[Test]
    public function execute_does_not_invalidate_cache_by_default(): void
    {
        $this->mockService->shouldNotReceive('invalidateConversations');
        $this->mockService->expects('getConversations')
            ->andReturn([]);

        $params = ConversationQueryParams::assigned(agentId: 12345);
        $this->useCase->execute($params);
    }

    #[Test]
    public function execute_invalidates_cache_when_force_refresh_is_true(): void
    {
        $params = ConversationQueryParams::assigned(agentId: 12345);

        $this->mockService->expects('invalidateConversations')
            ->with($params)
            ->once();

        $this->mockService->expects('getConversations')
            ->andReturn([]);

        $this->useCase->execute($params, forceRefresh: true);
    }

    #[Test]
    public function execute_invalidates_before_fetching(): void
    {
        $params = ConversationQueryParams::assigned(agentId: 12345);
        $callOrder = [];

        $this->mockService->expects('invalidateConversations')
            ->andReturnUsing(static function () use (&$callOrder): void {
                $callOrder[] = 'invalidate';
            });

        $this->mockService->expects('getConversations')
            ->andReturnUsing(static function () use (&$callOrder): array {
                $callOrder[] = 'getConversations';

                return [];
            });

        $this->useCase->execute($params, forceRefresh: true);

        $this->assertSame(['invalidate', 'getConversations'], $callOrder);
    }

    /*
    |--------------------------------------------------------------------------
    | Test Helpers
    |--------------------------------------------------------------------------
    */

    private function createConversation(int $id, string $status): Conversation
    {
        return new Conversation(
            id: $id,
            number: 1000 + $id,
            subject: "Test conversation {$id}",
            status: $status,
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
