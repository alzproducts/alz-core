<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Access;

use App\Application\Contracts\Access\AccessTokenVerifierInterface;
use App\Domain\Access\Exceptions\InvalidAccessTokenException;
use App\Infrastructure\Access\SupabaseAuthConfig;
use App\Infrastructure\Access\SupabaseJwksHttpTransport;
use App\Infrastructure\Access\SupabaseJwksKeySet;
use App\Infrastructure\Access\SupabaseJwtVerifier;
use App\Infrastructure\Support\LockableCache;
use App\Infrastructure\Support\ResilientCache;
use App\Infrastructure\Support\TransientLogThrottle;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Illuminate\Cache\CacheManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\Feature\Concerns\SignsSupabaseTokens;
use Tests\TestCase;

#[CoversClass(SupabaseJwtVerifier::class)]
final class SupabaseJwtVerifierTest extends TestCase
{
    use SignsSupabaseTokens;

    private const string PROJECT_URL = 'https://test.supabase.co';

    private const string JWKS_URL = self::PROJECT_URL . '/auth/v1/.well-known/jwks.json';

    private const string KID = 'current-kid';

    private SupabaseJwtVerifier $verifier;

    /** @var array{private_pem: string, jwk: array<string, string>} */
    private array $keyPair;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Log::spy();

        $cache = \app(CacheManager::class);
        $logger = \app(LoggerInterface::class);
        $config = SupabaseAuthConfig::fromProjectUrl(self::PROJECT_URL, allowInsecureHttp: false);

        $this->verifier = new SupabaseJwtVerifier($config, new SupabaseJwksKeySet(
            new SupabaseJwksHttpTransport($config, new TransientLogThrottle($cache, $logger)),
            new LockableCache($cache, $logger, 'supabase-jwks'),
            new ResilientCache($cache, $logger),
            $cache,
        ));

        $this->keyPair = $this->generateSupabaseKeyPair(self::KID);
        Http::fake([self::JWKS_URL => Http::response($this->supabaseJwksBody($this->keyPair['jwk']))]);
    }

    #[Override]
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    #[Test]
    public function it_returns_the_claims_of_a_valid_es256_token(): void
    {
        $claims = $this->verifier->verify($this->sign($this->supabaseClaims([
            'app_metadata' => ['is_approved' => true, 'role_name' => 'admin'],
        ])));

        $this->assertSame('d9dd22a9-c3ab-413b-8a93-25b462231a98', $claims->userId);
        $this->assertSame('test@example.com', $claims->email);
        $this->assertTrue($claims->isMfaVerified());
        $this->assertTrue($claims->isApproved);
        $this->assertSame('admin', $claims->roleName);
    }

    #[Test]
    public function it_accepts_an_audience_array_containing_authenticated(): void
    {
        $claims = $this->verifier->verify($this->sign($this->supabaseClaims([
            'aud' => ['other', 'authenticated'],
        ])));

        $this->assertSame('test@example.com', $claims->email);
    }

    #[Test]
    public function it_rejects_an_hs256_token_without_fetching_keys(): void
    {
        $token = JWT::encode($this->supabaseClaims(), \str_repeat('s', 64), 'HS256', self::KID);

        $this->assertRejectedWithoutRequest($token, 'unsupported_algorithm');
    }

    #[Test]
    public function it_rejects_an_alg_none_token_without_fetching_keys(): void
    {
        $token = self::encodeSegment(['alg' => 'none', 'typ' => 'JWT', 'kid' => self::KID])
            . '.' . self::encodeSegment($this->supabaseClaims()) . '.';

        $this->assertRejectedWithoutRequest($token, 'unsupported_algorithm');
    }

    #[Test]
    public function it_rejects_a_kid_less_token_without_fetching_keys(): void
    {
        $token = JWT::encode($this->supabaseClaims(), $this->keyPair['private_pem'], 'ES256');

        $this->assertRejectedWithoutRequest($token, 'missing_key_id');
    }

    #[Test]
    public function it_rejects_a_non_string_kid_without_fetching_keys(): void
    {
        $token = self::encodeSegment(['alg' => 'ES256', 'kid' => 123])
            . '.' . self::encodeSegment($this->supabaseClaims()) . '.c2ln';

        $this->assertRejectedWithoutRequest($token, 'missing_key_id');
    }

    #[Test]
    public function it_rejects_a_header_that_is_not_valid_json_without_fetching_keys(): void
    {
        $token = JWT::urlsafeB64Encode('{"alg":"ES256",') . '.' . self::encodeSegment($this->supabaseClaims()) . '.c2ln';

        $this->assertRejectedWithoutRequest($token, 'malformed_token');
    }

    #[Test]
    public function it_rejects_a_header_that_is_a_json_array_without_fetching_keys(): void
    {
        $token = JWT::urlsafeB64Encode('["ES256"]') . '.' . self::encodeSegment($this->supabaseClaims()) . '.c2ln';

        $this->assertRejectedWithoutRequest($token, 'malformed_token');
    }

    #[Test]
    public function it_rejects_a_token_without_three_segments_without_fetching_keys(): void
    {
        $this->assertRejectedWithoutRequest('abc.def', 'malformed_token');
    }

    #[Test]
    public function it_rejects_a_wrong_issuer(): void
    {
        $token = $this->sign($this->supabaseClaims(['iss' => 'https://other.supabase.co/auth/v1']));

        $this->assertRejected($token, 'issuer_mismatch');
    }

    #[Test]
    public function it_rejects_a_wrong_audience(): void
    {
        $this->assertRejected($this->sign($this->supabaseClaims(['aud' => 'anon'])), 'audience_mismatch');
    }

    #[Test]
    public function it_rejects_an_audience_array_without_authenticated(): void
    {
        $this->assertRejected($this->sign($this->supabaseClaims(['aud' => ['anon']])), 'audience_mismatch');
    }

    #[Test]
    public function it_rejects_an_expired_token(): void
    {
        $token = $this->sign($this->supabaseClaims(['iat' => \time() - 120, 'exp' => \time() - 60]));

        $exception = $this->assertRejected($token, 'verification_failed');

        $this->assertInstanceOf(ExpiredException::class, $exception->getPrevious());
        $this->assertSame(ExpiredException::class, $exception->context()['cause'] ?? null);
    }

    #[Test]
    public function it_rejects_a_token_without_expiry(): void
    {
        $claims = $this->supabaseClaims();
        unset($claims['exp']);

        $this->assertRejected($this->sign($claims), 'missing_expiry');
    }

    #[Test]
    public function it_rejects_an_unknown_kid_after_one_refetch(): void
    {
        $other = $this->generateSupabaseKeyPair('other-kid');
        $token = $this->signSupabaseToken($this->supabaseClaims(), $other['private_pem'], 'other-kid');

        $this->assertRejected($token, 'unknown_key_id');

        Http::assertSentCount(2);
    }

    #[Test]
    public function it_rejects_a_garbage_signature_segment(): void
    {
        [$header, $payload] = \explode('.', $this->sign($this->supabaseClaims()));

        $this->assertRejected("{$header}.{$payload}.garbage", 'verification_failed');
    }

    #[Test]
    public function it_rejects_a_signature_from_a_different_key_with_the_same_kid(): void
    {
        $impostor = $this->generateSupabaseKeyPair(self::KID);
        $token = $this->signSupabaseToken($this->supabaseClaims(), $impostor['private_pem'], self::KID);

        $this->assertRejected($token, 'verification_failed');
    }

    #[Test]
    public function it_rejects_a_signed_token_with_invalid_identity_claims(): void
    {
        $exception = $this->assertRejected($this->sign($this->supabaseClaims(['sub' => 'not-a-uuid'])), 'invalid_claim_value');

        $this->assertSame('sub', $exception->claim);
    }

    #[Test]
    public function the_contract_resolves_from_the_container(): void
    {
        $this->assertInstanceOf(SupabaseJwtVerifier::class, \app(AccessTokenVerifierInterface::class));
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function sign(array $claims): string
    {
        return $this->signSupabaseToken($claims, $this->keyPair['private_pem'], self::KID);
    }

    private function assertRejected(string $token, string $reason): InvalidAccessTokenException
    {
        try {
            $this->verifier->verify($token);
        } catch (InvalidAccessTokenException $e) {
            $this->assertSame($reason, $e->reason);

            return $e;
        }

        $this->fail('Expected InvalidAccessTokenException');
    }

    private function assertRejectedWithoutRequest(string $token, string $reason): void
    {
        $this->assertRejected($token, $reason);

        Http::assertNothingSent();
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function encodeSegment(array $data): string
    {
        return JWT::urlsafeB64Encode(JWT::jsonEncode($data));
    }
}
