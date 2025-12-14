<?php

declare(strict_types=1);

namespace Tests\Unit\Application\HelpScout\UseCases;

use App\Application\HelpScout\Queries\ConversationQueryParams;
use App\Application\HelpScout\Services\CachingHelpScoutService;
use App\Application\HelpScout\UseCases\GetEscalationsUseCase;
use App\Domain\CustomerService\ValueObjects\Conversation;
use App\Domain\CustomerService\ValueObjects\ConversationTag;
use App\Domain\CustomerService\ValueObjects\EscalationsConfig;
use DateTimeImmutable;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(GetEscalationsUseCase::class)]
final class GetEscalationsUseCaseTest extends TestCase
{
    private CachingHelpScoutService&MockInterface $mockService;

    private GetEscalationsUseCase $useCase;

    private const int SUPPORT_MAILBOX_ID = 100;

    private const int PURCHASE_ORDERS_MAILBOX_ID = 200;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockService = Mockery::mock(CachingHelpScoutService::class);
        $this->useCase = new GetEscalationsUseCase(
            $this->mockService,
            self::SUPPORT_MAILBOX_ID,
            self::PURCHASE_ORDERS_MAILBOX_ID,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | execute() Tests - Basic Behavior
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_returns_empty_array_when_no_conversations(): void
    {
        $this->mockService->expects('getEscalationsConfig')
            ->once()
            ->andReturn($this->createConfig());

        $this->mockService->expects('getConversationsBatch')
            ->once()
            ->andReturn([]);

        $result = $this->useCase->execute();

        $this->assertSame([], $result);
    }

    #[Test]
    public function execute_calls_get_escalations_config_once(): void
    {
        $this->mockService->expects('getEscalationsConfig')
            ->once()
            ->andReturn($this->createConfig());

        $this->mockService->expects('getConversationsBatch')
            ->andReturn([]);

        $this->useCase->execute();
    }

    #[Test]
    public function execute_builds_five_queries_from_config(): void
    {
        $config = $this->createConfig(
            priorityTags: ['urgent', 'vip'],
            excludedTags: ['spam'],
            assignedTag: 'server to-do',
            lateThresholdHours: 24,
            latePriorityThresholdHours: 4,
        );

        $this->mockService->expects('getEscalationsConfig')
            ->andReturn($config);

        $capturedQueries = [];
        $this->mockService->expects('getConversationsBatch')
            ->andReturnUsing(static function (array $queries) use (&$capturedQueries): array {
                $capturedQueries = $queries;

                return [];
            });

        $this->useCase->execute();

        $this->assertCount(5, $capturedQueries);
    }

    #[Test]
    public function execute_builds_support_mailbox_queries(): void
    {
        $config = $this->createConfig(
            priorityTags: ['urgent'],
            excludedTags: ['spam'],
            lateThresholdHours: 24,
            latePriorityThresholdHours: 4,
        );

        $this->mockService->expects('getEscalationsConfig')
            ->andReturn($config);

        $capturedQueries = [];
        $this->mockService->expects('getConversationsBatch')
            ->andReturnUsing(static function (array $queries) use (&$capturedQueries): array {
                $capturedQueries = $queries;

                return [];
            });

        $this->useCase->execute();

        // First two queries should be for support mailbox
        $query0CacheKey = $capturedQueries[0]->getCacheKey();
        $query1CacheKey = $capturedQueries[1]->getCacheKey();

        $this->assertStringContainsString('mailbox=' . self::SUPPORT_MAILBOX_ID, $query0CacheKey);
        $this->assertStringContainsString('mailbox=' . self::SUPPORT_MAILBOX_ID, $query1CacheKey);
    }

    #[Test]
    public function execute_builds_purchase_orders_mailbox_queries(): void
    {
        $config = $this->createConfig();

        $this->mockService->expects('getEscalationsConfig')
            ->andReturn($config);

        $capturedQueries = [];
        $this->mockService->expects('getConversationsBatch')
            ->andReturnUsing(static function (array $queries) use (&$capturedQueries): array {
                $capturedQueries = $queries;

                return [];
            });

        $this->useCase->execute();

        // Third and fourth queries should be for purchase orders mailbox
        $query2CacheKey = $capturedQueries[2]->getCacheKey();
        $query3CacheKey = $capturedQueries[3]->getCacheKey();

        $this->assertStringContainsString('mailbox=' . self::PURCHASE_ORDERS_MAILBOX_ID, $query2CacheKey);
        $this->assertStringContainsString('mailbox=' . self::PURCHASE_ORDERS_MAILBOX_ID, $query3CacheKey);
    }

    #[Test]
    public function execute_builds_manually_assigned_query(): void
    {
        $config = $this->createConfig(assignedTag: 'server to-do');

        $this->mockService->expects('getEscalationsConfig')
            ->andReturn($config);

        $capturedQueries = [];
        $this->mockService->expects('getConversationsBatch')
            ->andReturnUsing(static function (array $queries) use (&$capturedQueries): array {
                $capturedQueries = $queries;

                return [];
            });

        $this->useCase->execute();

        // Fifth query should be manually assigned (no specific mailbox)
        $query4CacheKey = $capturedQueries[4]->getCacheKey();

        $this->assertStringContainsString('manually-assigned', $query4CacheKey);
    }

    /*
    |--------------------------------------------------------------------------
    | execute() Tests - Deduplication
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_deduplicates_conversations_by_id(): void
    {
        $conversation1 = $this->createConversation(1, 'active');
        $conversation2 = $this->createConversation(2, 'active');
        $duplicate1 = $this->createConversation(1, 'active'); // Same ID as conversation1

        $this->mockService->expects('getEscalationsConfig')
            ->andReturn($this->createConfig());

        $this->mockService->expects('getConversationsBatch')
            ->andReturn([$conversation1, $conversation2, $duplicate1]);

        $result = $this->useCase->execute();

        $this->assertCount(2, $result);
        $ids = \array_map(static fn(Conversation $c) => $c->id, $result);
        $this->assertContains(1, $ids);
        $this->assertContains(2, $ids);
    }

    #[Test]
    public function execute_keeps_first_occurrence_when_deduplicating(): void
    {
        // Create two conversations with same ID but different subjects
        $first = new Conversation(
            id: 1,
            number: 1001,
            subject: 'First occurrence',
            status: 'active',
            mailboxId: 100,
            createdAt: new DateTimeImmutable('2024-12-14'),
            updatedAt: null,
            userUpdatedAt: null,
            customerWaitingSince: new DateTimeImmutable('2024-12-01'),
            snooze: null,
            tags: [],
            customer: null,
            assignee: null,
        );

        $second = new Conversation(
            id: 1,
            number: 1001,
            subject: 'Second occurrence',
            status: 'active',
            mailboxId: 100,
            createdAt: new DateTimeImmutable('2024-12-14'),
            updatedAt: null,
            userUpdatedAt: null,
            customerWaitingSince: new DateTimeImmutable('2024-12-01'),
            snooze: null,
            tags: [],
            customer: null,
            assignee: null,
        );

        $this->mockService->expects('getEscalationsConfig')
            ->andReturn($this->createConfig());

        $this->mockService->expects('getConversationsBatch')
            ->andReturn([$first, $second]);

        $result = $this->useCase->execute();

        $this->assertCount(1, $result);
        $this->assertSame('First occurrence', $result[0]->subject);
    }

    /*
    |--------------------------------------------------------------------------
    | execute() Tests - Sorting
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_sorts_by_priority_hierarchy(): void
    {
        $config = $this->createConfig(priorityTags: ['urgent'], assignedTag: 'server to-do');

        $standard = $this->createConversation(1, 'active', customerWaitingSince: new DateTimeImmutable('2024-01-01'));
        $priority = $this->createConversation(2, 'active', tags: [new ConversationTag(1, 'urgent', 'red')], customerWaitingSince: new DateTimeImmutable('2024-12-10'));
        $assigned = $this->createConversation(3, 'active', tags: [new ConversationTag(2, 'server to-do', 'blue')], customerWaitingSince: new DateTimeImmutable('2024-06-01'));

        $this->mockService->expects('getEscalationsConfig')
            ->andReturn($config);

        $this->mockService->expects('getConversationsBatch')
            ->andReturn([$standard, $priority, $assigned]);

        $result = $this->useCase->execute();

        // Priority first, then assigned, then standard
        $this->assertSame(2, $result[0]->id); // priority
        $this->assertSame(3, $result[1]->id); // assigned
        $this->assertSame(1, $result[2]->id); // standard
    }

    /*
    |--------------------------------------------------------------------------
    | execute() Tests - Cache Invalidation
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function execute_does_not_invalidate_cache_by_default(): void
    {
        $this->mockService->shouldNotReceive('invalidateConversations');

        $this->mockService->expects('getEscalationsConfig')
            ->andReturn($this->createConfig());

        $this->mockService->expects('getConversationsBatch')
            ->andReturn([]);

        $this->useCase->execute();
    }

    #[Test]
    public function execute_invalidates_all_five_queries_when_force_refresh_is_true(): void
    {
        $this->mockService->expects('getEscalationsConfig')
            ->andReturn($this->createConfig());

        $this->mockService->expects('invalidateConversations')
            ->with(Mockery::type(ConversationQueryParams::class))
            ->times(5);

        $this->mockService->expects('getConversationsBatch')
            ->andReturn([]);

        $this->useCase->execute(forceRefresh: true);
    }

    #[Test]
    public function execute_invalidates_before_fetching(): void
    {
        $callOrder = [];

        $this->mockService->expects('getEscalationsConfig')
            ->andReturn($this->createConfig());

        $this->mockService->expects('invalidateConversations')
            ->times(5)
            ->andReturnUsing(static function () use (&$callOrder): void {
                $callOrder[] = 'invalidate';
            });

        $this->mockService->expects('getConversationsBatch')
            ->andReturnUsing(static function () use (&$callOrder): array {
                $callOrder[] = 'fetch';

                return [];
            });

        $this->useCase->execute(forceRefresh: true);

        // All 5 invalidations should happen before fetch
        $this->assertSame(
            ['invalidate', 'invalidate', 'invalidate', 'invalidate', 'invalidate', 'fetch'],
            $callOrder,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Test Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @param list<string> $priorityTags
     * @param list<string> $excludedTags
     */
    private function createConfig(
        array $priorityTags = [],
        array $excludedTags = [],
        string $assignedTag = 'assigned',
        int $lateThresholdHours = 24,
        int $latePriorityThresholdHours = 4,
    ): EscalationsConfig {
        return new EscalationsConfig(
            lateThresholdHours: $lateThresholdHours,
            latePriorityThresholdHours: $latePriorityThresholdHours,
            priorityTags: $priorityTags,
            excludedTags: $excludedTags,
            assignedTag: $assignedTag,
        );
    }

    /**
     * @param list<ConversationTag> $tags
     */
    private function createConversation(
        int $id,
        string $status,
        array $tags = [],
        ?DateTimeImmutable $customerWaitingSince = null,
    ): Conversation {
        return new Conversation(
            id: $id,
            number: 1000 + $id,
            subject: "Test conversation {$id}",
            status: $status,
            mailboxId: 100,
            createdAt: new DateTimeImmutable('2024-12-14'),
            updatedAt: null,
            userUpdatedAt: null,
            customerWaitingSince: $customerWaitingSince,
            snooze: null,
            tags: $tags,
            customer: null,
            assignee: null,
        );
    }
}
