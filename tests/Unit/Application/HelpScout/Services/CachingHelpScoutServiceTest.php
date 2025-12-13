<?php

declare(strict_types=1);

namespace Tests\Unit\Application\HelpScout\Services;

use App\Application\Contracts\EscalationsConfigRepositoryInterface;
use App\Application\Contracts\HelpScout\AgentsClientInterface;
use App\Application\Contracts\HelpScout\ConversationsClientInterface;
use App\Application\Contracts\HelpScout\MailboxesClientInterface;
use App\Application\HelpScout\Queries\ConversationQueryParams;
use App\Application\HelpScout\Services\CachingHelpScoutService;
use App\Application\HelpScout\Support\MailboxEnrichmentService;
use App\Application\Support\GracefulCache;
use App\Domain\CustomerService\Exceptions\CustomerServiceAgentNotFoundException;
use App\Domain\CustomerService\ValueObjects\Conversation;
use App\Domain\CustomerService\ValueObjects\EscalationsConfig;
use App\Domain\CustomerService\ValueObjects\Mailbox;
use App\Domain\CustomerService\ValueObjects\SupportAgent;
use Closure;
use DateTimeImmutable;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(CachingHelpScoutService::class)]
final class CachingHelpScoutServiceTest extends TestCase
{
    private ConversationsClientInterface&MockInterface $mockConversationsClient;

    private AgentsClientInterface&MockInterface $mockAgentsClient;

    private MailboxesClientInterface&MockInterface $mockMailboxesClient;

    private EscalationsConfigRepositoryInterface&MockInterface $mockEscalationsConfigRepository;

    private MailboxEnrichmentService&MockInterface $mockEnricher;

    private GracefulCache&MockInterface $mockCache;

    private CachingHelpScoutService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockConversationsClient = Mockery::mock(ConversationsClientInterface::class);
        $this->mockAgentsClient = Mockery::mock(AgentsClientInterface::class);
        $this->mockMailboxesClient = Mockery::mock(MailboxesClientInterface::class);
        $this->mockEscalationsConfigRepository = Mockery::mock(EscalationsConfigRepositoryInterface::class);
        $this->mockEnricher = Mockery::mock(MailboxEnrichmentService::class);
        $this->mockCache = Mockery::mock(GracefulCache::class);

        $this->service = new CachingHelpScoutService(
            $this->mockConversationsClient,
            $this->mockAgentsClient,
            $this->mockMailboxesClient,
            $this->mockEscalationsConfigRepository,
            $this->mockEnricher,
            $this->mockCache,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | resolveAgentId() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function resolve_agent_id_returns_cached_id(): void
    {
        $this->mockCache->expects('rememberInt')
            ->with(
                Mockery::pattern('/^helpscout:agent:email:/'),
                604800, // 7 days
                Mockery::type(Closure::class),
            )
            ->once()
            ->andReturn(12345);

        $result = $this->service->resolveAgentId('agent@example.com');

        $this->assertSame(12345, $result);
    }

    #[Test]
    public function resolve_agent_id_normalizes_email_to_lowercase(): void
    {
        $normalizedKeyHash = \hash('xxh3', 'agent@example.com');
        $expectedKey = "helpscout:agent:email:{$normalizedKeyHash}";

        $this->mockCache->expects('rememberInt')
            ->with($expectedKey, Mockery::any(), Mockery::type(Closure::class))
            ->once()
            ->andReturn(12345);

        $this->service->resolveAgentId('AGENT@EXAMPLE.COM');
    }

    #[Test]
    public function resolve_agent_id_trims_email(): void
    {
        $normalizedKeyHash = \hash('xxh3', 'agent@example.com');
        $expectedKey = "helpscout:agent:email:{$normalizedKeyHash}";

        $this->mockCache->expects('rememberInt')
            ->with($expectedKey, Mockery::any(), Mockery::type(Closure::class))
            ->once()
            ->andReturn(12345);

        $this->service->resolveAgentId('  agent@example.com  ');
    }

    #[Test]
    public function resolve_agent_id_throws_when_agent_not_found(): void
    {
        $this->mockCache->expects('rememberInt')
            ->andReturn(null);

        $this->expectException(CustomerServiceAgentNotFoundException::class);
        $this->expectExceptionMessage('unknown@example.com');

        $this->service->resolveAgentId('unknown@example.com');
    }

    #[Test]
    public function resolve_agent_id_calls_agents_client_via_cache(): void
    {
        $agent = $this->createSupportAgent(99999, 'found@example.com');

        $this->mockAgentsClient->expects('findByEmail')
            ->with('found@example.com')
            ->once()
            ->andReturn($agent);

        $this->mockCache->expects('rememberInt')
            ->andReturnUsing(static fn(string $key, int $ttl, Closure $callback): ?int => $callback());

        $result = $this->service->resolveAgentId('found@example.com');

        $this->assertSame(99999, $result);
    }

    /*
    |--------------------------------------------------------------------------
    | getConversations() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function get_conversations_returns_cached_conversations(): void
    {
        $conversations = [$this->createConversation(1)];

        $this->mockCache->expects('remember')
            ->with(Mockery::any(), 300, Mockery::type(Closure::class))
            ->once()
            ->andReturn($conversations);

        $params = ConversationQueryParams::assigned(agentId: 12345);
        $result = $this->service->getConversations($params);

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]->id);
    }

    #[Test]
    public function get_conversations_uses_params_cache_key(): void
    {
        $params = ConversationQueryParams::assigned(agentId: 12345);

        $this->mockCache->expects('remember')
            ->with($params->getCacheKey(), Mockery::any(), Mockery::type(Closure::class))
            ->once()
            ->andReturn([]);

        $this->service->getConversations($params);
    }

    #[Test]
    public function get_conversations_enriches_fetched_conversations(): void
    {
        $rawConversations = [$this->createConversation(1)];
        $enrichedConversations = [$this->createConversation(1)->withMailboxName('Support')];

        $this->mockConversationsClient->expects('getConversations')
            ->once()
            ->andReturn($rawConversations);

        $this->mockEnricher->expects('enrich')
            ->with($rawConversations)
            ->once()
            ->andReturn($enrichedConversations);

        $this->mockCache->expects('remember')
            ->andReturnUsing(static fn(string $key, int $ttl, Closure $callback): array => $callback());

        $params = ConversationQueryParams::assigned(agentId: 12345);
        $result = $this->service->getConversations($params);

        $this->assertSame('Support', $result[0]->mailboxName);
    }

    /*
    |--------------------------------------------------------------------------
    | getConversationsBatch() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function get_conversations_batch_returns_empty_for_empty_queries(): void
    {
        $result = $this->service->getConversationsBatch([]);

        $this->assertSame([], $result);
    }

    #[Test]
    public function get_conversations_batch_returns_cached_results(): void
    {
        $conversation1 = $this->createConversation(1);
        $conversation2 = $this->createConversation(2);

        $params1 = ConversationQueryParams::assigned(agentId: 111);
        $params2 = ConversationQueryParams::assigned(agentId: 222);

        // Both cached
        $this->mockCache->expects('get')
            ->with($params1->getCacheKey())
            ->once()
            ->andReturn([$conversation1]);

        $this->mockCache->expects('get')
            ->with($params2->getCacheKey())
            ->once()
            ->andReturn([$conversation2]);

        $result = $this->service->getConversationsBatch([$params1, $params2]);

        $this->assertCount(2, $result);
    }

    #[Test]
    public function get_conversations_batch_fetches_uncached_queries(): void
    {
        $conversation = $this->createConversation(1);
        $params = ConversationQueryParams::assigned(agentId: 12345);

        // Not cached
        $this->mockCache->expects('get')
            ->andReturn(null);

        // Will be fetched
        $this->mockConversationsClient->expects('getConversationsBatch')
            ->once()
            ->andReturn([[$conversation]]);

        $this->mockEnricher->expects('enrich')
            ->once()
            ->andReturn([$conversation]);

        $this->mockCache->expects('put')
            ->once();

        $result = $this->service->getConversationsBatch([$params]);

        $this->assertCount(1, $result);
    }

    /*
    |--------------------------------------------------------------------------
    | invalidateConversations() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function invalidate_conversations_calls_cache_forget(): void
    {
        $params = ConversationQueryParams::assigned(agentId: 12345);

        $this->mockCache->expects('forget')
            ->with($params->getCacheKey())
            ->once();

        $this->service->invalidateConversations($params);
    }

    /*
    |--------------------------------------------------------------------------
    | getMailboxes() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function get_mailboxes_returns_cached_mailboxes(): void
    {
        $mailboxes = [
            new Mailbox(100, 'Support', 'support@example.com', 'support'),
        ];

        $this->mockCache->expects('remember')
            ->with('helpscout:mailboxes', 604800, Mockery::type(Closure::class))
            ->once()
            ->andReturn($mailboxes);

        $result = $this->service->getMailboxes();

        $this->assertCount(1, $result);
        $this->assertSame(100, $result[0]->id);
    }

    #[Test]
    public function get_mailboxes_calls_client_via_cache(): void
    {
        $mailboxes = [
            new Mailbox(200, 'Sales', 'sales@example.com', 'sales'),
        ];

        $this->mockMailboxesClient->expects('list')
            ->once()
            ->andReturn($mailboxes);

        $this->mockCache->expects('remember')
            ->andReturnUsing(static fn(string $key, int $ttl, Closure $callback): array => $callback());

        $result = $this->service->getMailboxes();

        $this->assertSame('Sales', $result[0]->name);
    }

    /*
    |--------------------------------------------------------------------------
    | getEscalationsConfig() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function get_escalations_config_returns_cached_config(): void
    {
        $config = $this->createEscalationsConfig();

        $this->mockCache->expects('remember')
            ->with('helpscout:escalation-config', 300, Mockery::type(Closure::class))
            ->once()
            ->andReturn($config);

        $result = $this->service->getEscalationsConfig();

        $this->assertSame(24, $result->lateThresholdHours);
    }

    #[Test]
    public function get_escalations_config_calls_repository_via_cache(): void
    {
        $config = $this->createEscalationsConfig();

        $this->mockEscalationsConfigRepository->expects('get')
            ->once()
            ->andReturn($config);

        $this->mockCache->expects('remember')
            ->andReturnUsing(static fn(string $key, int $ttl, Closure $callback): EscalationsConfig => $callback());

        $result = $this->service->getEscalationsConfig();

        $this->assertSame('server to-do', $result->assignedTag);
    }

    /*
    |--------------------------------------------------------------------------
    | Test Helpers
    |--------------------------------------------------------------------------
    */

    private function createSupportAgent(int $id, string $email): SupportAgent
    {
        return new SupportAgent(
            id: $id,
            email: $email,
            firstName: 'Test',
            lastName: 'Agent',
        );
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

    private function createEscalationsConfig(): EscalationsConfig
    {
        return new EscalationsConfig(
            lateThresholdHours: 24,
            latePriorityThresholdHours: 4,
            priorityTags: ['urgent'],
            excludedTags: [],
            assignedTag: 'server to-do',
        );
    }
}
