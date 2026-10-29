<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\HelpScout;

use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\HelpScout\Clients\ConversationWriteClient;
use App\Infrastructure\HelpScout\HelpScoutClientFactory;
use App\Infrastructure\HelpScout\Support\NoRetryRestClientBuilder;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use HelpScout\Api\ApiClient;
use HelpScout\Api\Http\RestClient;
use Illuminate\Support\Facades\Log;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tests\TestCase;

/**
 * SDK writes built by HelpScoutClientFactory send one request with the configured
 * timeout and surface a connection failure as a transient Domain exception.
 *
 * The Guzzle handler is swapped via Reflection: the SDK builder owns the handler
 * stack and exposes no seam for it.
 */
#[CoversClass(NoRetryRestClientBuilder::class)]
#[Group('unit')]
final class NoRetryRestClientBuilderTest extends TestCase
{
    private const int TIMEOUT_SECONDS = 7;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Log::spy();
        HelpScoutClientFactory::reset();

        \config([
            'helpscout.timeout_seconds' => self::TIMEOUT_SECONDS,
            'helpscout.mailboxes' => ['support' => 123],
            'helpscout.auth' => ['type' => 'client_credentials', 'appId' => 'app-id', 'appSecret' => 'app-secret'],
        ]);
    }

    #[Override]
    protected function tearDown(): void
    {
        HelpScoutClientFactory::reset();

        parent::tearDown();
    }

    #[Test]
    public function a_write_that_fails_to_connect_is_sent_once_with_the_configured_timeout(): void
    {
        $writeClient = HelpScoutClientFactory::createConversationWriteClient();
        $mockHandler = self::installMockHandler($writeClient, 5);

        $thrown = null;
        try {
            $writeClient->addNoteToConversation(IntId::from(42), 'note', IntId::from(7));
        } catch (ExternalServiceUnavailableException $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(ExternalServiceUnavailableException::class, $thrown);
        $this->assertInstanceOf(ConnectException::class, $thrown->getPrevious());
        $this->assertSame(4, $mockHandler->count(), 'Exactly one of the five queued failures should be consumed');
        $this->assertSame(self::TIMEOUT_SECONDS, $mockHandler->getLastOptions()['timeout'] ?? null);
        $this->assertSame('POST', $mockHandler->getLastRequest()?->getMethod());
    }

    /**
     * Replace the SDK's network handler with a MockHandler queued with connection failures.
     */
    private static function installMockHandler(ConversationWriteClient $writeClient, int $failures): MockHandler
    {
        $apiClient = (new ReflectionProperty(ConversationWriteClient::class, 'sdkClient'))->getValue($writeClient);
        self::assertInstanceOf(ApiClient::class, $apiClient);
        $apiClient->setAccessToken('test-access-token');

        $restClient = (new ReflectionProperty(ApiClient::class, 'restClient'))->getValue($apiClient);
        self::assertInstanceOf(RestClient::class, $restClient);

        $guzzleClient = (new ReflectionProperty(RestClient::class, 'client'))->getValue($restClient);
        self::assertInstanceOf(Client::class, $guzzleClient);

        $handlerStack = $guzzleClient->getConfig('handler');
        self::assertInstanceOf(HandlerStack::class, $handlerStack);

        $failure = new ConnectException('Connection timed out', new Request('POST', 'conversations/42/notes'));
        $mockHandler = new MockHandler(\array_fill(0, $failures, $failure));
        $handlerStack->setHandler($mockHandler);

        return $mockHandler;
    }
}
