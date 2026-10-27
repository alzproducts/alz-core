<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Access;

use App\Domain\Access\Exceptions\InvalidAccessTokenException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Infrastructure\Access\SupabaseAuthConfig;
use App\Infrastructure\Access\SupabaseJwksHttpTransport;
use App\Infrastructure\Access\SupabaseJwksKeySet;
use App\Infrastructure\Support\LockableCache;
use App\Infrastructure\Support\ResilientCache;
use App\Infrastructure\Support\TransientLogThrottle;
use Closure;
use Firebase\JWT\JWT;
use Illuminate\Cache\CacheManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\Feature\Concerns\SignsSupabaseTokens;
use Tests\TestCase;

#[CoversClass(SupabaseJwksKeySet::class)]
final class SupabaseJwksKeySetTest extends TestCase
{
    use SignsSupabaseTokens;

    private const string JWKS_URL = 'https://x.supabase.co/auth/v1/.well-known/jwks.json';

    private const string KID = 'current-kid';

    private SupabaseJwksKeySet $keySet;

    /** @var array{private_pem: string, jwk: array<string, string>} */
    private array $keyPair;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Log::spy();

        $cache = \app(CacheManager::class);
        $logger = \app(LoggerInterface::class);

        $this->keySet = new SupabaseJwksKeySet(
            new SupabaseJwksHttpTransport(
                SupabaseAuthConfig::fromProjectUrl('https://x.supabase.co', allowInsecureHttp: false),
                new TransientLogThrottle($cache, $logger),
            ),
            new LockableCache($cache, $logger, 'supabase-jwks'),
            new ResilientCache($cache, $logger),
            $cache,
        );

        $this->keyPair = $this->generateSupabaseKeyPair(self::KID);
    }

    #[Override]
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    #[Test]
    public function it_returns_an_es256_key_that_verifies_tokens_signed_with_the_matching_private_key(): void
    {
        Http::fake([self::JWKS_URL => Http::response($this->supabaseJwksBody($this->keyPair['jwk']))]);

        $key = $this->keySet->keyFor(self::KID);

        $token = $this->signSupabaseToken($this->supabaseClaims(), $this->keyPair['private_pem'], self::KID);
        $this->assertSame('ES256', $key->getAlgorithm());
        $this->assertSame('test@example.com', JWT::decode($token, $key)->email);
    }

    #[Test]
    public function it_accepts_a_key_without_alg_or_use(): void
    {
        $jwk = $this->keyPair['jwk'];
        unset($jwk['alg'], $jwk['use']);
        Http::fake([self::JWKS_URL => Http::response($this->supabaseJwksBody($jwk))]);

        $this->assertSame('ES256', $this->keySet->keyFor(self::KID)->getAlgorithm());
    }

    #[Test]
    public function it_serves_the_cached_set_without_a_request_within_600_seconds(): void
    {
        Http::fake([self::JWKS_URL => Http::response($this->supabaseJwksBody($this->keyPair['jwk']))]);

        $this->keySet->keyFor(self::KID);
        $this->travel(599)->seconds();
        $this->keySet->keyFor(self::KID);

        Http::assertSentCount(1);
    }

    #[Test]
    public function it_refetches_after_601_seconds(): void
    {
        Http::fake([self::JWKS_URL => Http::response($this->supabaseJwksBody($this->keyPair['jwk']))]);

        $this->keySet->keyFor(self::KID);
        $this->travel(601)->seconds();
        $this->keySet->keyFor(self::KID);

        Http::assertSentCount(2);
    }

    #[Test]
    public function it_refetches_once_for_an_unknown_kid_and_returns_a_rotated_key(): void
    {
        $rotated = $this->generateSupabaseKeyPair('rotated-kid');
        Http::fake([self::JWKS_URL => Http::sequence()
            ->push($this->supabaseJwksBody($this->keyPair['jwk']))
            ->push($this->supabaseJwksBody($this->keyPair['jwk'], $rotated['jwk']))]);

        $this->keySet->keyFor(self::KID);
        $key = $this->keySet->keyFor('rotated-kid');

        $token = $this->signSupabaseToken($this->supabaseClaims(), $rotated['private_pem'], 'rotated-kid');
        $this->assertSame('test@example.com', JWT::decode($token, $key)->email);
        Http::assertSentCount(2);
    }

    #[Test]
    public function it_makes_no_request_for_a_second_unknown_kid_within_the_60_second_cooldown(): void
    {
        Http::fake([self::JWKS_URL => Http::response($this->supabaseJwksBody($this->keyPair['jwk']))]);
        $this->keySet->keyFor(self::KID);

        $this->assertUnknownKeyId('unknown-1');
        Http::assertSentCount(2);

        $this->travel(59)->seconds();
        $this->assertUnknownKeyId('unknown-2');
        Http::assertSentCount(2);

        $this->travel(2)->seconds();
        $this->assertUnknownKeyId('unknown-3');
        Http::assertSentCount(3);
    }

    /**
     * @return iterable<string, array{Closure(array<string, string>): mixed}>
     */
    public static function unusableEntries(): iterable
    {
        yield 'RS256' => [static fn(array $jwk): array => ['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => 'bad', 'n' => 'sXch', 'e' => 'AQAB']];
        yield 'ES384' => [static fn(array $jwk): array => [...$jwk, 'kid' => 'bad', 'alg' => 'ES384']];
        yield 'P-384 curve' => [static fn(array $jwk): array => [...$jwk, 'kid' => 'bad', 'crv' => 'P-384']];
        yield 'use enc' => [static fn(array $jwk): array => [...$jwk, 'kid' => 'bad', 'use' => 'enc']];
        yield 'kid-less' => [static function (array $jwk): array {
            unset($jwk['kid']);

            return $jwk;
        }];
        yield 'empty kid' => [static fn(array $jwk): array => [...$jwk, 'kid' => '']];
        yield 'non-string x' => [static fn(array $jwk): array => [...$jwk, 'kid' => 'bad', 'x' => 123]];
        yield 'non-array' => [static fn(array $jwk): string => 'bad'];
        yield 'invalid point' => [static fn(array $jwk): array => [...$jwk, 'kid' => 'bad', 'y' => $jwk['x']]];
    }

    /**
     * @param Closure(array<string, string>): mixed $makeEntry
     */
    #[Test]
    #[DataProvider('unusableEntries')]
    public function it_never_returns_or_caches_an_unusable_key(Closure $makeEntry): void
    {
        $other = $this->generateSupabaseKeyPair('bad');
        Http::fake([self::JWKS_URL => Http::response([
            'keys' => [$this->keyPair['jwk'], $makeEntry($other['jwk'])],
        ])]);

        $this->assertUnknownKeyId('bad');

        $cached = Cache::get('supabase_jwks:keys');
        $this->assertIsArray($cached);
        $this->assertSame([self::KID], \array_keys($cached));
    }

    /**
     * @return iterable<string, array{Closure(): void}>
     */
    public static function fetchFailures(): iterable
    {
        yield 'connection error' => [static function (): void {
            Http::fake(static function (): never {
                throw new ConnectionException('Connection timed out');
            });
        }];
        yield '500' => [static fn() => Http::fake([self::JWKS_URL => Http::response('down', 500)])];
        yield '404' => [static fn() => Http::fake([self::JWKS_URL => Http::response('missing', 404)])];
        yield 'non-JSON' => [static fn() => Http::fake([self::JWKS_URL => Http::response('<html></html>', 200)])];
        yield 'empty set' => [static fn() => Http::fake([self::JWKS_URL => Http::response(['keys' => []])])];
    }

    /**
     * @param Closure(): void $fakeFailure
     */
    #[Test]
    #[DataProvider('fetchFailures')]
    public function it_throws_unavailable_when_the_fetch_fails(Closure $fakeFailure): void
    {
        $fakeFailure();

        $this->assertUnavailable(self::KID);
    }

    #[Test]
    public function it_logs_an_empty_filtered_set_at_error(): void
    {
        $jwk = [...$this->keyPair['jwk'], 'use' => 'enc'];
        Http::fake([self::JWKS_URL => Http::response($this->supabaseJwksBody($jwk))]);

        $this->assertUnavailable(self::KID);

        Log::shouldHaveReceived('error')->once()->with('Supabase JWKS contains no usable ES256 signing key');
    }

    #[Test]
    public function it_keeps_the_cached_set_when_a_refetch_fails(): void
    {
        Http::fake([self::JWKS_URL => Http::sequence()
            ->push($this->supabaseJwksBody($this->keyPair['jwk']))
            ->push('down', 500)]);
        $this->keySet->keyFor(self::KID);

        $this->assertUnavailable('rotated-kid');

        $this->assertSame('ES256', $this->keySet->keyFor(self::KID)->getAlgorithm());
        Http::assertSentCount(2);
    }

    #[Test]
    public function it_throws_without_a_request_within_10_seconds_of_a_failure_for_an_empty_cache(): void
    {
        Http::fake([self::JWKS_URL => Http::sequence()
            ->push('down', 500)
            ->push($this->supabaseJwksBody($this->keyPair['jwk']))]);

        $this->assertUnavailable(self::KID);
        $this->travel(9)->seconds();
        $this->assertUnavailable(self::KID);
        Http::assertSentCount(1);

        $this->travel(2)->seconds();
        $this->assertSame('ES256', $this->keySet->keyFor(self::KID)->getAlgorithm());
        Http::assertSentCount(2);
    }

    #[Test]
    public function it_throws_unavailable_without_a_request_for_an_unknown_kid_within_10_seconds_of_a_failure(): void
    {
        Http::fake([self::JWKS_URL => Http::sequence()
            ->push($this->supabaseJwksBody($this->keyPair['jwk']))
            ->push('down', 500)
            ->push($this->supabaseJwksBody($this->keyPair['jwk']))]);
        $this->keySet->keyFor(self::KID);
        $this->assertUnavailable('rotated-kid');

        $this->travel(9)->seconds();
        $this->assertUnavailable('other-kid');

        Http::assertSentCount(2);
    }

    private function assertUnknownKeyId(string $kid): void
    {
        try {
            $this->keySet->keyFor($kid);
            $this->fail('Expected InvalidAccessTokenException');
        } catch (InvalidAccessTokenException $e) {
            $this->assertSame('unknown_key_id', $e->reason);
        }
    }

    private function assertUnavailable(string $kid): void
    {
        try {
            $this->keySet->keyFor($kid);
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame('supabase-jwks', $e->serviceName);
        }
    }
}
