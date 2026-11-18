<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\QuickBooks;

use App\Application\Contracts\QuickBooks\QuickBooksOAuthClientInterface;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Infrastructure\QuickBooks\QuickBooksConfig;
use App\Infrastructure\QuickBooks\QuickBooksOAuthClient;
use App\Infrastructure\QuickBooks\QuickBooksOAuthHttpTransport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(QuickBooksOAuthClient::class)]
#[CoversClass(QuickBooksOAuthHttpTransport::class)]
final class QuickBooksOAuthClientTest extends TestCase
{
    private const string NOW = '2026-11-18T10:00:00+00:00';

    private QuickBooksOAuthClientInterface $client;

    private QuickBooksConfig $config;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo(new CarbonImmutable(self::NOW));
        $this->config = $this->app->make(QuickBooksConfig::class);
        $this->client = $this->app->make(QuickBooksOAuthClientInterface::class);
    }

    #[Test]
    public function a_refresh_posts_the_refresh_token_with_basic_auth_and_maps_absolute_expiries(): void
    {
        Http::fake([QuickBooksConfig::TOKEN_URL => Http::response(self::tokenBody())]);

        $grant = $this->client->refresh(new ApiKeyToken('old-refresh'));

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $basic = 'Basic ' . \base64_encode($this->config->clientId . ':' . $this->config->clientSecret);

            return $request->method() === 'POST'
                && $request->url() === QuickBooksConfig::TOKEN_URL
                && $request->hasHeader('Authorization', $basic)
                && $request->data() === ['grant_type' => 'refresh_token', 'refresh_token' => 'old-refresh'];
        });
        $this->assertSame('new-access', $grant->accessToken->value);
        $this->assertSame('2026-11-18T11:00:00+00:00', $grant->accessTokenExpiresAt->format(DATE_ATOM));
        $this->assertSame('new-refresh', $grant->connection->refreshToken->value);
        $this->assertSame('2027-02-27T10:00:00+00:00', $grant->connection->refreshTokenExpiresAt->format(DATE_ATOM));
        $this->assertSame('2031-11-17T10:00:00+00:00', $grant->connection->refreshTokenHardExpiresAt?->format(DATE_ATOM));
    }

    #[Test]
    public function a_code_exchange_posts_the_code_and_the_configured_redirect_uri(): void
    {
        Http::fake([QuickBooksConfig::TOKEN_URL => Http::response(self::tokenBody())]);

        $this->client->exchangeCode('auth-code');

        Http::assertSent(fn(Request $request): bool => $request->data() === [
            'grant_type' => 'authorization_code',
            'code' => 'auth-code',
            'redirect_uri' => $this->config->redirectUri,
        ]);
    }

    #[Test]
    public function a_missing_hard_expiry_maps_to_null(): void
    {
        $body = self::tokenBody();
        unset($body['x_refresh_token_hard_expires_in']);
        Http::fake([QuickBooksConfig::TOKEN_URL => Http::response($body)]);

        $this->assertNull($this->client->refresh(new ApiKeyToken('old-refresh'))->connection->refreshTokenHardExpiresAt);
    }

    #[Test]
    public function an_invalid_grant_raises_authentication_expired_and_logs_no_token(): void
    {
        Log::spy();
        Http::fake([QuickBooksConfig::TOKEN_URL => Http::response(['error' => 'invalid_grant'], 400)]);
        $client = $this->app->make(QuickBooksOAuthClientInterface::class);

        try {
            $client->refresh(new ApiKeyToken('old-refresh'));
            $this->fail('Expected AuthenticationExpiredException');
        } catch (AuthenticationExpiredException) {
        }

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => $context === ['status' => 400, 'error' => 'invalid_grant']);
    }

    #[Test]
    public function a_401_raises_authentication_expired(): void
    {
        Http::fake([QuickBooksConfig::TOKEN_URL => Http::response(['error' => 'invalid_client'], 401)]);

        $this->expectException(AuthenticationExpiredException::class);

        $this->client->refresh(new ApiKeyToken('old-refresh'));
    }

    /**
     * @return iterable<string, array{int, array<string, string>}>
     */
    public static function transientStatuses(): iterable
    {
        yield 'other 400' => [400, ['error' => 'invalid_request']];
        yield 'rate limited' => [429, []];
        yield 'server error' => [503, []];
    }

    /**
     * @param array<string, string> $body
     */
    #[Test]
    #[DataProvider('transientStatuses')]
    public function a_non_auth_failure_status_raises_external_service_unavailable(int $status, array $body): void
    {
        Http::fake([QuickBooksConfig::TOKEN_URL => Http::response($body, $status)]);

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->client->refresh(new ApiKeyToken('old-refresh'));
    }

    #[Test]
    public function a_connection_failure_raises_external_service_unavailable(): void
    {
        Http::fake(static fn() => throw new ConnectionException('timed out'));

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->client->refresh(new ApiKeyToken('old-refresh'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function requiredFields(): iterable
    {
        yield 'access token' => ['access_token'];
        yield 'refresh token' => ['refresh_token'];
        yield 'access expiry' => ['expires_in'];
        yield 'refresh expiry' => ['x_refresh_token_expires_in'];
    }

    #[Test]
    #[DataProvider('requiredFields')]
    public function a_token_body_missing_a_required_field_raises_invalid_api_response(string $field): void
    {
        $body = self::tokenBody();
        unset($body[$field]);
        Http::fake([QuickBooksConfig::TOKEN_URL => Http::response($body)]);

        $this->expectException(InvalidApiResponseException::class);

        $this->client->refresh(new ApiKeyToken('old-refresh'));
    }

    #[Test]
    public function a_non_json_success_body_raises_invalid_api_response(): void
    {
        Http::fake([QuickBooksConfig::TOKEN_URL => Http::response('not json')]);

        $this->expectException(InvalidApiResponseException::class);

        $this->client->refresh(new ApiKeyToken('old-refresh'));
    }

    /**
     * @return array<string, string|int>
     */
    private static function tokenBody(): array
    {
        return [
            'token_type' => 'bearer',
            'access_token' => 'new-access',
            'expires_in' => 3600,
            'refresh_token' => 'new-refresh',
            'x_refresh_token_expires_in' => 8_726_400,
            'x_refresh_token_hard_expires_in' => 157_680_000,
        ];
    }
}
