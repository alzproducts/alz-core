<?php

declare(strict_types=1);

namespace Tests\Unit\Application\HelpScout\Support;

use App\Application\Contracts\HelpScout\MailboxesClientInterface;
use App\Application\Contracts\ResilientCacheInterface;
use App\Application\HelpScout\Support\MailboxEnrichmentService;
use App\Domain\CustomerService\ValueObjects\Conversation;
use App\Domain\CustomerService\ValueObjects\Mailbox;
use Closure;
use DateTimeImmutable;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(MailboxEnrichmentService::class)]
final class MailboxEnrichmentServiceTest extends TestCase
{
    private MailboxesClientInterface&MockInterface $mockMailboxesClient;

    private ResilientCacheInterface&MockInterface $mockCache;

    private MailboxEnrichmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockMailboxesClient = Mockery::mock(MailboxesClientInterface::class);
        $this->mockCache = Mockery::mock(ResilientCacheInterface::class);

        $this->service = new MailboxEnrichmentService(
            $this->mockMailboxesClient,
            $this->mockCache,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | enrich() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function enrich_returns_empty_array_for_empty_input(): void
    {
        // Should not call cache or client
        $this->mockCache->shouldNotReceive('remember');
        $this->mockMailboxesClient->shouldNotReceive('list');

        $result = $this->service->enrich([]);

        $this->assertSame([], $result);
    }

    #[Test]
    public function enrich_adds_mailbox_name_to_conversations(): void
    {
        $mailboxes = [
            new Mailbox(100, 'Support Inbox', 'support@example.com', 'support'),
            new Mailbox(200, 'Sales Inbox', 'sales@example.com', 'sales'),
        ];

        $this->mockCache->expects('remember')
            ->with('helpscout:mailboxes', Mockery::any(), Mockery::type(Closure::class))
            ->once()
            ->andReturnUsing(static fn(string $key, int $ttl, Closure $callback): array => $mailboxes);

        $conversation = $this->createConversation(1, 100);

        $result = $this->service->enrich([$conversation]);

        $this->assertCount(1, $result);
        $this->assertSame('Support Inbox', $result[0]->mailboxName);
    }

    #[Test]
    public function enrich_sets_null_for_unknown_mailbox_id(): void
    {
        $mailboxes = [
            new Mailbox(100, 'Support Inbox', 'support@example.com', 'support'),
        ];

        $this->mockCache->expects('remember')
            ->andReturnUsing(static fn(string $key, int $ttl, Closure $callback): array => $mailboxes);

        $conversation = $this->createConversation(1, 999); // Unknown mailbox

        $result = $this->service->enrich([$conversation]);

        $this->assertNull($result[0]->mailboxName);
    }

    #[Test]
    public function enrich_enriches_multiple_conversations(): void
    {
        $mailboxes = [
            new Mailbox(100, 'Support', 'support@example.com', 'support'),
            new Mailbox(200, 'Sales', 'sales@example.com', 'sales'),
        ];

        $this->mockCache->expects('remember')
            ->once()
            ->andReturnUsing(static fn(string $key, int $ttl, Closure $callback): array => $mailboxes);

        $conversations = [
            $this->createConversation(1, 100),
            $this->createConversation(2, 200),
            $this->createConversation(3, 100),
        ];

        $result = $this->service->enrich($conversations);

        $this->assertCount(3, $result);
        $this->assertSame('Support', $result[0]->mailboxName);
        $this->assertSame('Sales', $result[1]->mailboxName);
        $this->assertSame('Support', $result[2]->mailboxName);
    }

    #[Test]
    public function enrich_caches_mailbox_lookup_for_seven_days(): void
    {
        $mailboxes = [];

        $this->mockCache->expects('remember')
            ->with('helpscout:mailboxes', 604800, Mockery::type(Closure::class)) // 7 days in seconds
            ->once()
            ->andReturnUsing(static fn(string $key, int $ttl, Closure $callback): array => $mailboxes);

        $this->service->enrich([$this->createConversation(1, 100)]);
    }

    #[Test]
    public function enrich_calls_mailboxes_client_via_cache_callback(): void
    {
        $mailboxes = [
            new Mailbox(100, 'Support', 'support@example.com', 'support'),
        ];

        $this->mockMailboxesClient->expects('list')
            ->once()
            ->andReturn($mailboxes);

        $this->mockCache->expects('remember')
            ->andReturnUsing(static function (string $key, int $ttl, Closure $callback): array {
                // Execute the callback to test the client is called
                return $callback();
            });

        $result = $this->service->enrich([$this->createConversation(1, 100)]);

        $this->assertSame('Support', $result[0]->mailboxName);
    }

    #[Test]
    public function enrich_preserves_original_conversation_data(): void
    {
        $mailboxes = [new Mailbox(100, 'Support', 'support@example.com', 'support')];

        $this->mockCache->expects('remember')
            ->andReturnUsing(static fn(string $key, int $ttl, Closure $callback): array => $mailboxes);

        $original = $this->createConversation(42, 100);

        $result = $this->service->enrich([$original]);

        $this->assertSame(42, $result[0]->id);
        $this->assertSame(1042, $result[0]->number);
        $this->assertSame('Test subject', $result[0]->subject);
        $this->assertSame('active', $result[0]->status);
        $this->assertSame(100, $result[0]->mailboxId);
    }

    /*
    |--------------------------------------------------------------------------
    | Test Helpers
    |--------------------------------------------------------------------------
    */

    private function createConversation(int $id, int $mailboxId): Conversation
    {
        return new Conversation(
            id: $id,
            number: 1000 + $id,
            subject: 'Test subject',
            status: 'active',
            mailboxId: $mailboxId,
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
