<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\HelpScout;

use App\Application\HelpScout\Queries\ConversationQueryParams;
use App\Infrastructure\HelpScout\Clients\ConversationsClient;
use App\Infrastructure\HelpScout\HelpScoutConfig;
use App\Infrastructure\HelpScout\HelpScoutErrorHandler;
use App\Infrastructure\HelpScout\HelpScoutHttpTransport;
use App\Infrastructure\Support\TransientLogThrottle;
use DateTimeImmutable;
use HelpScout\Api\ApiClient;
use HelpScout\Api\Http\Authenticator;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(ConversationsClient::class)]
final class ConversationsClientFindMatchesTest extends TestCase
{
    private ConversationsClient $client;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $authenticator = Mockery::mock(Authenticator::class);
        $authenticator->allows('getAuthHeader')->andReturn(['Authorization' => 'Bearer test-token']);

        $sdkClient = Mockery::mock(ApiClient::class);
        $sdkClient->allows('getAuthenticator')->andReturn($authenticator);

        /** @var HttpFactory $factory */
        $factory = Http::getFacadeRoot();

        $this->client = new ConversationsClient(new HelpScoutHttpTransport(
            new HelpScoutConfig(mailboxes: ['support' => 1], timeoutSeconds: 30, retryAttempts: 3),
            $sdkClient,
            $factory,
            new HelpScoutErrorHandler(\app(TransientLogThrottle::class)),
        ));
    }

    #[Test]
    public function the_contact_form_retry_search_sends_status_all_in_one_ten_second_request(): void
    {
        $timeout = null;
        $query = [];

        Http::fake(static function (Request $request, array $options) use (&$timeout, &$query): mixed {
            $timeout = $options['timeout'] ?? null;
            \parse_str((string) \parse_url($request->url(), \PHP_URL_QUERY), $query);

            return Http::response(['_embedded' => ['conversations' => [self::conversationPayload()]]]);
        });

        $params = ConversationQueryParams::contactFormRetry(
            'jane@example.com',
            new DateTimeImmutable('2024-01-15T09:59:00Z'),
            321,
        );
        self::assertNotNull($params);

        $conversations = $this->client->findMatches($params);

        self::assertCount(1, $conversations);
        self::assertSame(777, $conversations[0]->id);
        self::assertSame('jane@example.com', $conversations[0]->customer?->email);
        self::assertSame(10, $timeout);
        Http::assertSentCount(1);
        self::assertSame([
            'status' => 'all',
            'mailbox' => '321',
            'query' => '(email:"jane@example.com" AND createdAt:[2024-01-15T09:59:00Z TO *])',
            'sortField' => 'createdAt',
            'sortOrder' => 'desc',
        ], $query);
    }

    /**
     * @return array<string, mixed>
     */
    private static function conversationPayload(): array
    {
        return [
            'id' => 777,
            'number' => 1001,
            'subject' => '[Other] Contact Us Form',
            'status' => 'active',
            'type' => 'email',
            'mailboxId' => 321,
            'folderId' => null,
            'createdAt' => '2024-01-15T10:00:05Z',
            'updatedAt' => null,
            'userUpdatedAt' => null,
            'closedAt' => null,
            'primaryCustomer' => ['id' => 5, 'type' => 'customer', 'first' => 'Jane', 'last' => 'Doe', 'email' => 'jane@example.com'],
            'assignee' => null,
            'customerWaitingSince' => null,
            'snooze' => null,
            'tags' => [],
            'preview' => null,
            'threads' => null,
        ];
    }
}
