<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Auth\Middleware;

use App\Presentation\Http\Auth\Middleware\ValidateSupabaseJwtMiddleware;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\SignsSupabaseTokens;
use Tests\TestCase;

#[CoversClass(ValidateSupabaseJwtMiddleware::class)]
final class ValidateSupabaseJwtTest extends TestCase
{
    use SignsSupabaseTokens;

    private const string ROUTE = '/api/_test/protected-route';

    private const string JWKS_URL = 'https://test.supabase.co/auth/v1/.well-known/jwks.json';

    private const string KID = 'current-kid';

    private const string BYPASS_SECRET = 'local-bypass-secret';

    private const string MFA_FLAG = 'SUPABASE_REQUIRE_MFA';

    /** @var array{private_pem: string, jwk: array<string, string>} */
    private array $keyPair;

    private MockInterface $securityLogger;

    private bool $jwksAvailable = true;

    /** @var array{string|false, mixed, mixed}|null */
    private ?array $savedMfaFlag = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        Log::clearResolvedInstances();
        Log::spy();
        $this->securityLogger = Mockery::spy();
        Log::shouldReceive('channel')->andReturnUsing(
            fn(?string $channel = null): MockInterface => $channel === 'security' ? $this->securityLogger : Mockery::spy(),
        );

        Route::get(self::ROUTE, static function (Request $request) {
            $user = $request->attributes->get('authenticated_user');

            return \response()->json([
                'auth_user_id' => $user?->id,
                'auth_user_email' => $user?->email,
                'departments' => $user?->departments,
            ]);
        })->middleware(ValidateSupabaseJwtMiddleware::class);

        $this->keyPair = $this->generateSupabaseKeyPair(self::KID);
        Http::fake([self::JWKS_URL => fn() => $this->jwksAvailable
            ? Http::response($this->supabaseJwksBody($this->keyPair['jwk']))
            : Http::response('upstream error', 500)]);
    }

    #[Override]
    protected function tearDown(): void
    {
        if ($this->savedMfaFlag !== null) {
            self::writeMfaFlag(...$this->savedMfaFlag);
        }
        Mockery::close();

        parent::tearDown();
    }

    #[Test]
    public function it_attaches_the_authenticated_user_for_a_valid_aal2_token(): void
    {
        $token = $this->sign($this->supabaseClaims([
            'app_metadata' => ['departments_summary' => ['Sales', 'Support']],
        ]));

        $this->withToken($token)->getJson(self::ROUTE)
            ->assertOk()
            ->assertExactJson([
                'auth_user_id' => 'd9dd22a9-c3ab-413b-8a93-25b462231a98',
                'auth_user_email' => 'test@example.com',
                'departments' => ['Sales', 'Support'],
            ]);
    }

    #[Test]
    public function it_rejects_an_aal1_token_with_403(): void
    {
        $token = $this->sign($this->supabaseClaims(['aal' => 'aal1']));

        $this->withToken($token)->getJson(self::ROUTE)
            ->assertForbidden()
            ->assertJson(['error' => ['type' => 'forbidden', 'message' => 'MFA verification required.']]);

        $this->securityLogger->shouldHaveReceived('warning')->once()->with(
            'MFA not verified - AAL2 required',
            Mockery::on(static fn(array $context): bool => $context['event'] === 'api.auth.mfa_required'
                && $context['aal_level'] === 'aal1'
                && $context['user_id'] === 'd9dd22a9-c3ab-413b-8a93-25b462231a98'
                && $context['email'] === 'test@example.com'
                && $context['path'] === 'api/_test/protected-route'),
        );
    }

    #[Test]
    public function it_accepts_an_aal1_token_in_testing_when_the_mfa_flag_is_false(): void
    {
        $this->setMfaFlag('false');
        $token = $this->sign($this->supabaseClaims(['aal' => 'aal1']));

        $this->assertSame('testing', \app()->environment());
        $this->withToken($token)->getJson(self::ROUTE)
            ->assertOk()
            ->assertJson([
                'auth_user_id' => 'd9dd22a9-c3ab-413b-8a93-25b462231a98',
                'auth_user_email' => 'test@example.com',
            ]);
        $this->securityLogger->shouldNotHaveReceived('warning');
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function enforcingMfaFlags(): iterable
    {
        yield 'unset' => [null];
        yield 'empty' => [''];
        yield 'true' => ['true'];
        yield 'no' => ['no'];
    }

    #[Test]
    #[DataProvider('enforcingMfaFlags')]
    public function it_rejects_an_aal1_token_with_403_for_every_mfa_flag_but_false(?string $flag): void
    {
        $this->setMfaFlag($flag);
        $token = $this->sign($this->supabaseClaims(['aal' => 'aal1']));

        $this->withToken($token)->getJson(self::ROUTE)
            ->assertForbidden()
            ->assertJson(['error' => ['type' => 'forbidden', 'message' => 'MFA verification required.']]);

        $this->securityLogger->shouldHaveReceived('warning')->once()->with(
            'MFA not verified - AAL2 required',
            Mockery::on(static fn(array $context): bool => $context['event'] === 'api.auth.mfa_required'),
        );
    }

    #[Test]
    public function it_rejects_a_missing_token_with_401(): void
    {
        $this->getJson(self::ROUTE)
            ->assertUnauthorized()
            ->assertJson(['error' => ['type' => 'unauthorized', 'message' => 'Missing authorization token.']]);

        $this->assertMissingTokenLogged();
    }

    #[Test]
    public function it_rejects_an_empty_bearer_token_with_401(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer '])->getJson(self::ROUTE)
            ->assertUnauthorized()
            ->assertJson(['error' => ['type' => 'unauthorized', 'message' => 'Missing authorization token.']]);

        $this->assertMissingTokenLogged();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rejectedTokens(): iterable
    {
        yield 'malformed token' => ['malformed', 'malformed_token'];
        yield 'HS256 token' => ['hs256', 'unsupported_algorithm'];
        yield 'token without kid' => ['no_kid', 'missing_key_id'];
        yield 'unknown kid' => ['unknown_kid', 'unknown_key_id'];
        yield 'garbage signature' => ['garbage_signature', 'verification_failed'];
        yield 'expired token' => ['expired', 'verification_failed'];
        yield 'wrong issuer' => ['wrong_issuer', 'issuer_mismatch'];
        yield 'wrong audience' => ['wrong_audience', 'audience_mismatch'];
        yield 'non-UUID sub' => ['non_uuid_sub', 'invalid_claim_value'];
        yield 'missing email' => ['missing_email', 'missing_claim'];
    }

    #[Test]
    #[DataProvider('rejectedTokens')]
    public function it_rejects_an_invalid_token_with_401(string $case, string $reason): void
    {
        $this->withToken($this->rejectedToken($case))->getJson(self::ROUTE)
            ->assertUnauthorized()
            ->assertJson(['error' => ['type' => 'unauthorized', 'message' => 'Invalid or expired token.']]);

        $this->securityLogger->shouldHaveReceived('warning')->once()->with(
            'Invalid JWT token',
            Mockery::on(static fn(array $context): bool => $context['event'] === 'api.auth.invalid_token'
                && $context['error'] === 'Invalid access token'
                && $context['reason'] === $reason
                && $context['path'] === 'api/_test/protected-route'
                && \array_key_exists('ip', $context)
                && \array_key_exists('user_agent', $context)),
        );
    }

    #[Test]
    public function it_renders_503_when_the_jwks_endpoint_fails(): void
    {
        $this->jwksAvailable = false;
        $token = $this->sign($this->supabaseClaims());

        $this->withToken($token)->getJson(self::ROUTE)
            ->assertStatus(503)
            ->assertJsonPath('error.type', 'service_unavailable');

        $this->securityLogger->shouldNotHaveReceived('warning');
    }

    #[Test]
    public function it_bypasses_verification_locally_with_the_matching_secret(): void
    {
        $this->enableLocalBypass();

        $this->withHeaders(['X-Local-Bypass' => self::BYPASS_SECRET])->getJson(self::ROUTE)
            ->assertOk()
            ->assertJson([
                'auth_user_id' => '00000000-0000-0000-0000-000000000001',
                'auth_user_email' => 'developer@example.com',
            ]);

        Http::assertNothingSent();
    }

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function nonLocalRequests(): iterable
    {
        yield 'remote peer forging X-Forwarded-For' => ['172.18.0.1', ['X-Forwarded-For' => '127.0.0.1']];
        yield 'remote peer forging IPv6 X-Forwarded-For' => ['172.18.0.1', ['X-Forwarded-For' => '::1']];
        yield 'remote peer without forwarding headers' => ['172.18.0.1', []];
        yield 'local proxy relaying a public client' => ['127.0.0.1', ['X-Forwarded-For' => '203.0.113.7']];
    }

    /**
     * @param array<string, string> $headers
     */
    #[Test]
    #[DataProvider('nonLocalRequests')]
    public function it_does_not_bypass_for_a_non_local_request(string $remoteAddress, array $headers): void
    {
        $this->enableLocalBypass();

        $this->withServerVariables(['REMOTE_ADDR' => $remoteAddress])
            ->withHeaders(['X-Local-Bypass' => self::BYPASS_SECRET, ...$headers])
            ->getJson(self::ROUTE)
            ->assertUnauthorized()
            ->assertJson(['error' => ['type' => 'unauthorized', 'message' => 'Missing authorization token.']]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function loopbackAddresses(): iterable
    {
        yield 'IPv4 loopback' => ['127.0.0.1'];
        yield 'IPv6 loopback' => ['::1'];
    }

    #[Test]
    #[DataProvider('loopbackAddresses')]
    public function it_bypasses_for_a_swoole_connection_from_a_loopback_peer(string $peer): void
    {
        $this->enableLocalBypass();
        $this->bindSwooleWorker($peer);

        $this->withHeaders(['X-Local-Bypass' => self::BYPASS_SECRET])->getJson(self::ROUTE)
            ->assertOk()
            ->assertJsonPath('auth_user_email', 'developer@example.com');
    }

    /**
     * REMOTE_ADDR reads 127.0.0.1, as after Octane merges a `Remote-Addr` header over it.
     */
    #[Test]
    public function it_does_not_bypass_when_a_swoole_peer_is_remote_but_remote_addr_reads_loopback(): void
    {
        $this->enableLocalBypass();
        $this->bindSwooleWorker('172.18.0.1');

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders(['X-Local-Bypass' => self::BYPASS_SECRET])
            ->getJson(self::ROUTE)
            ->assertUnauthorized();
    }

    #[Test]
    public function it_does_not_bypass_when_the_swoole_peer_cannot_be_established(): void
    {
        $this->enableLocalBypass();
        $this->bindSwooleWorker('127.0.0.1', hasTimerTable: false);

        $this->withHeaders(['X-Local-Bypass' => self::BYPASS_SECRET])->getJson(self::ROUTE)
            ->assertUnauthorized();
    }

    #[Test]
    public function it_ignores_a_mismatched_local_bypass_secret(): void
    {
        $this->enableLocalBypass();

        $this->withHeaders(['X-Local-Bypass' => 'wrong-secret'])->getJson(self::ROUTE)
            ->assertUnauthorized()
            ->assertJson(['error' => ['type' => 'unauthorized', 'message' => 'Missing authorization token.']]);
    }

    /**
     * Sets SUPABASE_REQUIRE_MFA (null unsets it) and re-reads config/services.php, so the value takes
     * the real env() → config path rather than being written to config directly.
     */
    private function setMfaFlag(?string $value): void
    {
        $this->savedMfaFlag ??= [\getenv(self::MFA_FLAG), $_ENV[self::MFA_FLAG] ?? null, $_SERVER[self::MFA_FLAG] ?? null];
        self::writeMfaFlag($value ?? false, $value, $value);

        /** @var array{supabase: array<string, mixed>} $services */
        $services = require \config_path('services.php');
        \config(['services.supabase.require_mfa' => $services['supabase']['require_mfa']]);
    }

    private static function writeMfaFlag(string|false $putenv, mixed $env, mixed $server): void
    {
        \putenv($putenv === false ? self::MFA_FLAG : self::MFA_FLAG . '=' . $putenv);
        if ($env === null) {
            unset($_ENV[self::MFA_FLAG]);
        } else {
            $_ENV[self::MFA_FLAG] = $env;
        }
        if ($server === null) {
            unset($_SERVER[self::MFA_FLAG]);
        } else {
            $_SERVER[self::MFA_FLAG] = $server;
        }
    }

    private function enableLocalBypass(): void
    {
        $this->app['env'] = 'local';
        \config([
            'services.supabase.local_bypass_secret' => self::BYPASS_SECRET,
            'services.supabase.local_test_email' => 'dev@alzadmin.test',
            'local-development.test_user_personas' => [
                'dev@alzadmin.test' => [
                    'email' => 'developer@example.com',
                    'user_id' => '00000000-0000-0000-0000-000000000001',
                    'is_approved' => true,
                    'role_name' => 'admin',
                    'departments' => null,
                ],
            ],
        ]);
    }

    /**
     * Stand-in for Octane's Swoole worker state: CI runs without ext-swoole.
     */
    private function bindSwooleWorker(string $peer, bool $hasTimerTable = true): void
    {
        $this->app->instance('Laravel\Octane\Swoole\WorkerState', (object) [
            'workerId' => 3,
            'timerTable' => $hasTimerTable ? new class {
                public function get(string $key, string $field): int|false
                {
                    return ($key === '3') && ($field === 'fd') ? 42 : false;
                }
            } : null,
            'server' => new class ($peer) {
                public function __construct(private readonly string $peer) {}

                /**
                 * @return array{remote_ip: string}|false
                 */
                public function getClientInfo(int|false $fd): array|false
                {
                    return $fd === 42 ? ['remote_ip' => $this->peer] : false;
                }
            },
        ]);
    }

    private function rejectedToken(string $case): string
    {
        $claims = $this->supabaseClaims();

        return match ($case) {
            'malformed' => 'this.is.not-a-jwt',
            'hs256' => JWT::encode($claims, 'a-very-secure-secret-key-for-testing-only', 'HS256', self::KID),
            'no_kid' => JWT::encode($claims, $this->keyPair['private_pem'], 'ES256'),
            'unknown_kid' => $this->signSupabaseToken($claims, $this->keyPair['private_pem'], 'unknown-kid'),
            'garbage_signature' => \implode('.', [...\array_slice(\explode('.', $this->sign($claims)), 0, 2), 'garbage']),
            'expired' => $this->sign($this->supabaseClaims(['exp' => \time() - 3600])),
            'wrong_issuer' => $this->sign($this->supabaseClaims(['iss' => 'https://other.supabase.co/auth/v1'])),
            'wrong_audience' => $this->sign($this->supabaseClaims(['aud' => 'anon'])),
            'non_uuid_sub' => $this->sign($this->supabaseClaims(['sub' => 'not-a-uuid'])),
            'missing_email' => $this->sign(\array_diff_key($claims, ['email' => true])),
        };
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function sign(array $claims): string
    {
        return $this->signSupabaseToken($claims, $this->keyPair['private_pem'], self::KID);
    }

    private function assertMissingTokenLogged(): void
    {
        $this->securityLogger->shouldHaveReceived('warning')->once()->with(
            'Missing authorization token',
            Mockery::on(static fn(array $context): bool => $context['event'] === 'api.auth.missing_token'
                && $context['path'] === 'api/_test/protected-route'
                && \array_key_exists('ip', $context)
                && \array_key_exists('user_agent', $context)),
        );
    }
}
