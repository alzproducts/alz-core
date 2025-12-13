<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\HelpScout\Clients;

use App\Domain\CustomerService\ValueObjects\Mailbox;
use App\Infrastructure\HelpScout\Clients\MailboxesClient;
use App\Infrastructure\HelpScout\HelpScoutHttpTransport;
use Illuminate\Http\Client\Response;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(MailboxesClient::class)]
final class MailboxesClientTest extends TestCase
{
    private HelpScoutHttpTransport&MockInterface $mockTransport;

    private MailboxesClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockTransport = Mockery::mock(HelpScoutHttpTransport::class);
        $this->client = new MailboxesClient($this->mockTransport);
    }

    #[Test]
    public function list_returns_domain_mailboxes(): void
    {
        $mockResponse = $this->createMockResponse([
            '_embedded' => [
                'mailboxes' => [
                    [
                        'id' => 12345,
                        'name' => 'Support Inbox',
                        'email' => 'support@example.com',
                        'slug' => 'support',
                        'createdAt' => '2024-01-01T00:00:00Z',
                        'updatedAt' => '2024-12-01T12:00:00Z',
                    ],
                    [
                        'id' => 67890,
                        'name' => 'Sales Inbox',
                        'email' => 'sales@example.com',
                        'slug' => 'sales',
                        'createdAt' => '2024-02-01T00:00:00Z',
                        'updatedAt' => '2024-12-10T08:00:00Z',
                    ],
                ],
            ],
        ]);

        $this->mockTransport->expects('get')
            ->with('/mailboxes')
            ->once()
            ->andReturn($mockResponse);

        $result = $this->client->list();

        $this->assertCount(2, $result);
        $this->assertInstanceOf(Mailbox::class, $result[0]);
        $this->assertInstanceOf(Mailbox::class, $result[1]);
        $this->assertSame(12345, $result[0]->id);
        $this->assertSame('Support Inbox', $result[0]->name);
        $this->assertSame(67890, $result[1]->id);
        $this->assertSame('Sales Inbox', $result[1]->name);
    }

    #[Test]
    public function list_returns_empty_array_when_no_mailboxes(): void
    {
        $mockResponse = $this->createMockResponse([
            '_embedded' => [
                'mailboxes' => [],
            ],
        ]);

        $this->mockTransport->expects('get')
            ->with('/mailboxes')
            ->once()
            ->andReturn($mockResponse);

        $result = $this->client->list();

        $this->assertSame([], $result);
    }

    /**
     * @param array<string, mixed> $json
     */
    private function createMockResponse(array $json): Response&MockInterface
    {
        $mockResponse = Mockery::mock(Response::class);
        $mockResponse->allows('json')->andReturn($json);

        return $mockResponse;
    }
}
