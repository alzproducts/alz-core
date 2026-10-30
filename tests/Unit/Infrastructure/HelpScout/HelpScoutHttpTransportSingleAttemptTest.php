<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\HelpScout;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Infrastructure\HelpScout\HelpScoutConfig;
use App\Infrastructure\HelpScout\HelpScoutErrorHandler;
use App\Infrastructure\HelpScout\HelpScoutHttpTransport;
use App\Infrastructure\Support\TransientLogThrottle;
use HelpScout\Api\ApiClient;
use HelpScout\Api\Http\Authenticator;
use Illuminate\Cache\CacheManager;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\AbstractLogger;
use Stringable;
use Tests\TestCase;

#[CoversClass(HelpScoutHttpTransport::class)]
#[CoversClass(HelpScoutErrorHandler::class)]
final class HelpScoutHttpTransportSingleAttemptTest extends TestCase
{
    private const string EMAIL = 'jane.doe@example.com';

    private const array QUERY_PARAMS = [
        'status' => 'all',
        'query' => '(email:"' . self::EMAIL . '" AND createdAt:[2024-01-15T09:59:00Z TO *])',
    ];

    private Authenticator&MockInterface $authenticator;

    /** @var AbstractLogger&object{records: list<array{mixed, string, array<array-key, mixed>}>} */
    private AbstractLogger $logs;

    private HelpScoutHttpTransport $transport;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->authenticator = Mockery::mock(Authenticator::class);
        $this->authenticator->allows('getAuthHeader')->andReturn(['Authorization' => 'Bearer test-token']);
        $this->authenticator->shouldNotReceive('fetchAccessAndRefreshToken');

        $sdkClient = Mockery::mock(ApiClient::class);
        $sdkClient->allows('getAuthenticator')->andReturn($this->authenticator);

        $config = new HelpScoutConfig(mailboxes: ['support' => 1], timeoutSeconds: 30, retryAttempts: 3);

        $this->logs = new class extends AbstractLogger {
            /** @var list<array{mixed, string, array<array-key, mixed>}> */
            public array $records = [];

            #[Override]
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message, $context];
            }
        };

        Log::swap($this->logs);
        $errorHandler = new HelpScoutErrorHandler(new TransientLogThrottle(\app(CacheManager::class), $this->logs));

        /** @var HttpFactory $factory */
        $factory = Http::getFacadeRoot();

        $this->transport = new HelpScoutHttpTransport($config, $sdkClient, $factory, $errorHandler);
    }

    #[Test]
    public function it_sends_exactly_one_request_with_the_given_timeout(): void
    {
        $timeout = null;

        Http::fake(static function (Request $request, array $options) use (&$timeout): mixed {
            $timeout = $options['timeout'] ?? null;

            return Http::response(['_embedded' => ['conversations' => []]]);
        });

        $response = $this->transport->getSingleAttempt('/conversations', self::QUERY_PARAMS, 10);

        self::assertSame(200, $response->status());
        self::assertSame(10, $timeout);
        Http::assertSentCount(1);
        Http::assertSent(static fn(Request $request): bool => $request->method() === 'GET'
            && \str_starts_with($request->url(), HelpScoutConfig::BASE_URL . '/conversations?')
            && $request['status'] === 'all'
            && $request['query'] === self::QUERY_PARAMS['query']);
    }

    #[Test]
    public function a_timeout_becomes_unavailable_without_the_query_string_or_a_previous_exception(): void
    {
        Http::fake(['*' => Http::failedConnection()]);

        try {
            $this->transport->getSingleAttempt('/conversations', self::QUERY_PARAMS, 10);
            self::fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            self::assertSame('HelpScout', $e->serviceName);
            self::assertSame('External service unavailable', $e->getMessage());
            self::assertNull($e->getPrevious());
            self::assertNull($e->retryAfter);
        }

        Http::assertSentCount(1);
        $this->assertNothingLoggedContains(self::EMAIL);
        $this->assertNothingLoggedContains('query');
    }

    #[Test]
    public function a_server_error_is_not_retried(): void
    {
        Http::fake(['*' => Http::response(['message' => self::EMAIL], 503)]);

        try {
            $this->transport->getSingleAttempt('/conversations', self::QUERY_PARAMS, 10);
            self::fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            self::assertNull($e->getPrevious());
        }

        Http::assertSentCount(1);
        $this->assertNothingLoggedContains(self::EMAIL);
    }

    #[Test]
    public function a_401_is_not_retried_with_a_fresh_token(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Unauthorized'], 401)]);

        try {
            $this->transport->getSingleAttempt('/conversations', self::QUERY_PARAMS, 10);
            self::fail('Expected AuthenticationExpiredException');
        } catch (AuthenticationExpiredException $e) {
            self::assertSame('Invalid credentials', $e->detail);
            self::assertNull($e->getPrevious());
        }

        Http::assertSentCount(1);
    }

    #[Test]
    public function a_bad_request_does_not_log_the_response_body(): void
    {
        Http::fake(['*' => Http::response(['_embedded' => ['errors' => [['rejectedValue' => self::EMAIL]]]], 400)]);

        try {
            $this->transport->getSingleAttempt('/conversations', self::QUERY_PARAMS, 10);
            self::fail('Expected InvalidApiRequestException');
        } catch (InvalidApiRequestException $e) {
            self::assertSame('Invalid request parameters', $e->detail);
            self::assertNull($e->getPrevious());
        }

        self::assertSame([['error', 'HelpScout API redacted request failed', ['status' => 400]]], $this->logs->records);
        $this->assertNothingLoggedContains(self::EMAIL);
    }

    #[Test]
    public function a_rate_limit_carries_retry_after(): void
    {
        Http::fake(['*' => Http::response([], 429, ['Retry-After' => '42'])]);

        try {
            $this->transport->getSingleAttempt('/conversations', self::QUERY_PARAMS, 10);
            self::fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            self::assertSame(42, $e->retryAfter);
        }

        Http::assertSentCount(1);
    }

    private function assertNothingLoggedContains(string $needle): void
    {
        self::assertNotSame([], $this->logs->records);

        foreach ($this->logs->records as $record) {
            self::assertStringNotContainsString($needle, \json_encode($record, \JSON_THROW_ON_ERROR));
        }
    }
}
