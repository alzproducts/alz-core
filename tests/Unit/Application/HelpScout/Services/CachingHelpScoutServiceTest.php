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
use App\Application\Support\EmailAliasResolver;
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

/**
 * Tests for CachingHelpScoutService.
 *
 * Focus: TTL values, cache invalidation, error paths, and business logic (alias resolution).
 * Not tested: Cache key patterns, delegation verification (covered by integration tests).
 */
#[CoversClass(CachingHelpScoutService::class)]
final class CachingHelpScoutServiceTest extends TestCase
{
    private ConversationsClientInterface&MockInterface $mockConversationsClient;

    private AgentsClientInterface&MockInterface $mockAgentsClient;

    private MailboxesClientInterface&MockInterface $mockMailboxesClient;

    private EscalationsConfigRepositoryInterface&MockInterface $mockEscalationsConfigRepository;

    private MailboxEnrichmentService&MockInterface $mockEnricher;

    private GracefulCache&MockInterface $mockCache;

    private EmailAliasResolver $emailResolver;

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
        $this->emailResolver = new EmailAliasResolver([]);

        $this->service = new CachingHelpScoutService(
            $this->mockConversationsClient,
            $this->mockAgentsClient,
            $this->mockMailboxesClient,
            $this->mockEscalationsConfigRepository,
            $this->mockEnricher,
            $this->mockCache,
            $this->emailResolver,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | getAgentProfile() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function get_agent_profile_caches_for_seven_days(): void
    {
        $agent = $this->createSupportAgent(12345, 'agent@example.com', 'admin');

        $this->mockCache->expects('remember')
            ->with(
                Mockery::pattern('/^helpscout:agent:profile:/'),
                604800, // 7 days in seconds
                Mockery::type(Closure::class),
            )
            ->once()
            ->andReturn($agent);

        $result = $this->service->getAgentProfile('agent@example.com');

        $this->assertSame(12345, $result->id);
    }

    #[Test]
    public function get_agent_profile_throws_when_agent_not_found(): void
    {
        $this->mockCache->expects('remember')
            ->andReturn(null);

        $this->expectException(CustomerServiceAgentNotFoundException::class);
        $this->expectExceptionMessage('Customer service agent not found');

        $this->service->getAgentProfile('unknown@example.com');
    }

    #[Test]
    public function get_agent_profile_resolves_email_alias_before_lookup(): void
    {
        $resolver = new EmailAliasResolver([
            'tom.murray@example.com' => 'tom@example.com',
        ]);
        $service = new CachingHelpScoutService(
            $this->mockConversationsClient,
            $this->mockAgentsClient,
            $this->mockMailboxesClient,
            $this->mockEscalationsConfigRepository,
            $this->mockEnricher,
            $this->mockCache,
            $resolver,
        );

        $agent = $this->createSupportAgent(12345, 'tom@example.com', 'admin');

        $this->mockAgentsClient->expects('findByEmail')
            ->with('tom@example.com')
            ->once()
            ->andReturn($agent);

        $this->mockCache->expects('remember')
            ->andReturnUsing(static fn(string $key, int $ttl, Closure $callback): ?SupportAgent => $callback());

        // Call with aliased email, expect canonical email used for lookup
        $result = $service->getAgentProfile('tom.murray@example.com');

        $this->assertSame('tom@example.com', $result->email);
    }

    /*
    |--------------------------------------------------------------------------
    | getConversations() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function get_conversations_caches_for_five_minutes(): void
    {
        $conversations = [$this->createConversation(1)];

        $this->mockCache->expects('remember')
            ->with(Mockery::any(), 300, Mockery::type(Closure::class)) // 5 minutes
            ->once()
            ->andReturn($conversations);

        $params = ConversationQueryParams::assigned(agentId: 12345);
        $result = $this->service->getConversations($params);

        $this->assertCount(1, $result);
    }

    /*
    |--------------------------------------------------------------------------
    | invalidateConversations() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function invalidate_conversations_clears_cache(): void
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
    public function get_mailboxes_caches_for_seven_days(): void
    {
        $mailboxes = [
            new Mailbox(100, 'Support', 'support@example.com', 'support'),
        ];

        $this->mockCache->expects('remember')
            ->with('helpscout:mailboxes', 604800, Mockery::type(Closure::class)) // 7 days
            ->once()
            ->andReturn($mailboxes);

        $result = $this->service->getMailboxes();

        $this->assertCount(1, $result);
    }

    /*
    |--------------------------------------------------------------------------
    | getEscalationsConfig() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function get_escalations_config_caches_for_five_minutes(): void
    {
        $config = $this->createEscalationsConfig();

        $this->mockCache->expects('remember')
            ->with('helpscout:escalation-config', 300, Mockery::type(Closure::class)) // 5 minutes
            ->once()
            ->andReturn($config);

        $result = $this->service->getEscalationsConfig();

        $this->assertSame(24, $result->lateThresholdHours);
    }

    /*
    |--------------------------------------------------------------------------
    | Test Helpers
    |--------------------------------------------------------------------------
    */

    private function createSupportAgent(int $id, string $email, ?string $role = null): SupportAgent
    {
        return new SupportAgent(
            id: $id,
            email: $email,
            firstName: 'Test',
            lastName: 'Agent',
            role: $role,
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
