<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Access;

use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Infrastructure\Access\SupabaseAuthConfig;
use App\Infrastructure\Access\SupabaseJwksHttpTransport;
use App\Infrastructure\Support\TransientLogThrottle;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Cache\CacheManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(SupabaseJwksHttpTransport::class)]
final class SupabaseJwksHttpTransportTest extends TestCase
{
    private const string JWKS_URL = 'https://x.supabase.co/auth/v1/.well-known/jwks.json';

    private SupabaseJwksHttpTransport $transport;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Log::spy();

        $this->transport = new SupabaseJwksHttpTransport(
            SupabaseAuthConfig::fromProjectUrl('https://x.supabase.co', allowInsecureHttp: false),
            new TransientLogThrottle(\app(CacheManager::class), \app(LoggerInterface::class)),
        );
    }

    #[Override]
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    #[Test]
    public function it_returns_the_unfiltered_keys_member(): void
    {
        $keys = [['kty' => 'RSA', 'kid' => 'rsa'], 'not-an-array', ['kty' => 'EC', 'kid' => 'ec']];
        Http::fake([self::JWKS_URL => Http::response(['keys' => $keys])]);

        $this->assertSame($keys, $this->transport->fetchKeys());
    }

    #[Test]
    public function it_requests_the_jwks_url_with_short_timeouts_and_no_apikey(): void
    {
        $seenOptions = [];
        Http::fake(static function (Request $request, array $options) use (&$seenOptions): PromiseInterface {
            $seenOptions = $options;

            return Http::response(['keys' => []]);
        });

        $this->transport->fetchKeys();

        $this->assertSame(2, $seenOptions['connect_timeout'] ?? null);
        $this->assertSame(5, $seenOptions['timeout'] ?? null);
        Http::assertSentCount(1);
        Http::assertSent(static fn(Request $request): bool => $request->method() === 'GET'
            && $request->url() === self::JWKS_URL
            && $request->hasHeader('Accept', 'application/json')
            && !$request->hasHeader('apikey'));
    }

    #[Test]
    public function it_translates_a_connection_error(): void
    {
        $connectionException = new ConnectionException('Connection timed out');
        Http::fake(static function () use ($connectionException): never {
            throw $connectionException;
        });

        try {
            $this->transport->fetchKeys();
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame('supabase-jwks', $e->serviceName);
            $this->assertSame($connectionException, $e->getPrevious());
        }
    }

    #[Test]
    public function it_translates_a_server_error_through_the_transient_throttle(): void
    {
        Http::fake([self::JWKS_URL => Http::response('upstream down', 500)]);

        $this->expectUnavailable();

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(static fn(string $message, array $context): bool => $message === 'Supabase JWKS request failed'
                && $context['status'] === 500
                && \array_key_exists('note', $context));
    }

    #[Test]
    public function it_logs_a_not_found_at_error(): void
    {
        Http::fake([self::JWKS_URL => Http::response('not found', 404)]);

        $this->expectUnavailable();

        Log::shouldHaveReceived('error')
            ->once()
            ->with('Supabase JWKS request returned an unexpected status', ['url' => self::JWKS_URL, 'status' => 404]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedBodies(): iterable
    {
        yield 'not JSON' => ['<html>gateway</html>'];
        yield 'JSON without keys' => ['{"other":[]}'];
        yield 'keys not an array' => ['{"keys":"abc"}'];
        yield 'JSON scalar' => ['"keys"'];
    }

    #[Test]
    #[DataProvider('malformedBodies')]
    public function it_translates_a_body_that_is_not_a_key_set(string $body): void
    {
        Http::fake([self::JWKS_URL => Http::response($body, 200)]);

        $this->expectUnavailable();

        Log::shouldHaveReceived('error')
            ->once()
            ->with('Supabase JWKS response is not a JSON object with a keys array', ['url' => self::JWKS_URL]);
    }

    private function expectUnavailable(): void
    {
        try {
            $this->transport->fetchKeys();
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame('supabase-jwks', $e->serviceName);
        }
    }
}
