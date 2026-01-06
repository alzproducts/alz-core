<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Middleware;

use App\Presentation\Http\Middleware\ValidateSupabaseJwtMiddleware;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Mockery;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(ValidateSupabaseJwtMiddleware::class)]
final class ValidateSupabaseJwtTest extends TestCase
{
    /**
     * The test JWT secret to use for signing tokens.
     */
    private const string TEST_SECRET = 'a-very-secure-secret-key-for-testing-only';

    /**
     * Set up a test route protected by the middleware before each test.
     */
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // Clear any facade mock state from previous tests to ensure isolation.
        // This is critical for parallel test execution where mock state can leak.
        Log::clearResolvedInstances();

        // Define a test route protected by the middleware under test.
        // This route echoes back the user details the middleware should have attached.
        Route::get('/_test/protected-route', static function (Request $request) {
            $user = $request->attributes->get('authenticated_user');

            return \response()->json([
                'auth_user_id' => $user?->id,
                'auth_user_email' => $user?->email,
                'departments' => $user?->departments,
            ]);
        })->middleware(ValidateSupabaseJwtMiddleware::class);

        // Set the JWT secret for the duration of the tests.
        \config(['services.supabase.jwt_secret' => self::TEST_SECRET]);
    }

    /**
     * Clean up Mockery expectations after each test.
     */
    #[Override]
    protected function tearDown(): void
    {
        // Explicitly close Mockery to prevent mock state from leaking to subsequent tests.
        Mockery::close();

        parent::tearDown();
    }

    /**
     * Generates a JWT for testing purposes.
     *
     * @param  array<string, mixed>  $payload  Custom claims to include in the token.
     * @param  string  $secret  The secret to sign the token with. Defaults to the test secret.
     */
    private function generateToken(array $payload, string $secret = self::TEST_SECRET): string
    {
        $defaultPayload = [
            'iss' => 'supabase',
            'iat' => \time(),
            'exp' => \time() + 3600, // Expires in 1 hour
            'aud' => 'authenticated',
            'role' => 'authenticated',
            'sub' => 'd9dd22a9-c3ab-413b-8a93-25b462231a98',
            'email' => 'test@example.com',
            'aal' => 'aal2', // MFA verified (required for API access)
        ];

        return JWT::encode(\array_merge($defaultPayload, $payload), $secret, 'HS256');
    }

    /**
     * Test that a request with no token returns 401 and logs the failure.
     */
    #[Test]
    public function returns_unauthorized_if_no_token_is_provided(): void
    {
        // Arrange
        $logger = Mockery::mock();
        $logger->shouldReceive('warning')
            ->once()
            ->with(
                'Missing authorization token',
                Mockery::on(static fn(array $context): bool => $context['event'] === 'api.auth.missing_token'
                    && $context['path'] === '_test/protected-route'
                    && \array_key_exists('ip', $context)
                    && \array_key_exists('user_agent', $context)),
            );

        Log::shouldReceive('channel')
            ->with('security')
            ->andReturn($logger);

        // Act
        $response = $this->getJson('/_test/protected-route');

        // Assert
        $response->assertStatus(401)->assertJson(['error' => 'Unauthorized']);
    }

    /**
     * Test that a token signed with the wrong secret is rejected.
     */
    #[Test]
    public function returns_unauthorized_for_token_with_invalid_signature(): void
    {
        // Arrange
        $logger = Mockery::mock();
        $logger->shouldReceive('warning')
            ->once()
            ->with(
                'Invalid JWT token',
                Mockery::on(static fn(array $context): bool => $context['event'] === 'api.auth.invalid_token'
                    && $context['error'] === 'Signature verification failed'
                    && \array_key_exists('ip', $context)
                    && \array_key_exists('path', $context)
                    && \array_key_exists('user_agent', $context)),
            );

        Log::shouldReceive('channel')
            ->with('security')
            ->andReturn($logger);

        $token = $this->generateToken([], 'this-is-the-wrong-secret');

        // Act
        $response = $this->withToken($token)->getJson('/_test/protected-route');

        // Assert
        $response->assertStatus(401)->assertJson(['error' => 'Unauthorized']);
    }

    /**
     * Test that an expired token is rejected.
     */
    #[Test]
    public function returns_unauthorized_for_expired_token(): void
    {
        // Arrange
        $logger = Mockery::mock();
        $logger->shouldReceive('warning')
            ->once()
            ->with(
                'Invalid JWT token',
                Mockery::on(static fn(array $context): bool => $context['event'] === 'api.auth.invalid_token'
                    && $context['error'] === 'Expired token'
                    && \array_key_exists('ip', $context)
                    && \array_key_exists('path', $context)
                    && \array_key_exists('user_agent', $context)),
            );

        Log::shouldReceive('channel')
            ->with('security')
            ->andReturn($logger);

        $payload = ['exp' => \time() - 3600]; // Expired 1 hour ago
        $token = $this->generateToken($payload);

        // Act
        $response = $this->withToken($token)->getJson('/_test/protected-route');

        // Assert
        $response->assertStatus(401)->assertJson(['error' => 'Unauthorized']);
    }

    /**
     * Test that middleware fails gracefully when JWT secret is not configured.
     */
    #[Test]
    public function returns_unauthorized_if_jwt_secret_is_not_configured(): void
    {
        // Arrange
        $logger = Mockery::mock();
        $logger->shouldReceive('warning')
            ->once()
            ->with(
                'Invalid JWT token',
                Mockery::on(static fn(array $context): bool => $context['error'] === 'SUPABASE_JWT_SECRET not configured'
                    && \array_key_exists('event', $context)
                    && \array_key_exists('ip', $context)
                    && \array_key_exists('path', $context)
                    && \array_key_exists('user_agent', $context)),
            );

        Log::shouldReceive('channel')
            ->with('security')
            ->andReturn($logger);

        \config(['services.supabase.jwt_secret' => null]);
        $token = $this->generateToken([]); // Token is valid, but server config is not

        // Act
        $response = $this->withToken($token)->getJson('/_test/protected-route');

        // Assert
        $response->assertStatus(401)->assertJson(['error' => 'Unauthorized']);
    }

    /**
     * Test that a malformed token (not valid JWT format) is rejected.
     */
    #[Test]
    public function returns_unauthorized_for_malformed_token(): void
    {
        // Arrange
        $logger = Mockery::mock();
        $logger->shouldReceive('warning')
            ->once()
            ->with(
                'Invalid JWT token',
                Mockery::on(static fn(array $context): bool => $context['event'] === 'api.auth.invalid_token'
                    && \array_key_exists('ip', $context)
                    && \array_key_exists('path', $context)
                    && \array_key_exists('user_agent', $context)
                    && \array_key_exists('error', $context)),
            );

        Log::shouldReceive('channel')
            ->with('security')
            ->andReturn($logger);

        // Act
        $response = $this->withToken('this.is.not.a.jwt')->getJson('/_test/protected-route');

        // Assert
        $response->assertStatus(401)->assertJson(['error' => 'Unauthorized']);
    }

    /**
     * Test that a token missing the required 'sub' claim is rejected.
     */
    #[Test]
    public function returns_unauthorized_if_sub_claim_is_missing(): void
    {
        // Arrange
        $logger = Mockery::mock();
        $logger->shouldReceive('warning')
            ->once()
            ->with(
                'Invalid JWT token',
                Mockery::on(static fn(array $context): bool => \str_contains($context['error'], "required claim 'sub' is missing")
                    && \array_key_exists('event', $context)
                    && \array_key_exists('ip', $context)
                    && \array_key_exists('path', $context)
                    && \array_key_exists('user_agent', $context)),
            );

        Log::shouldReceive('channel')
            ->with('security')
            ->andReturn($logger);

        $payload = ['sub' => null];
        $token = $this->generateToken($payload);

        // Act
        $response = $this->withToken($token)->getJson('/_test/protected-route');

        // Assert
        $response->assertStatus(401)->assertJson(['error' => 'Unauthorized']);
    }

    /**
     * Test that a valid token successfully authenticates and attaches user data to the request.
     */
    #[Test]
    public function succeeds_and_attaches_user_data_to_request_for_valid_token(): void
    {
        // Arrange - no Log mocking needed for success paths
        $userId = 'd9dd22a9-c3ab-413b-8a93-25b462231a98';
        $userEmail = 'test@example.com';
        $payload = ['sub' => $userId, 'email' => $userEmail];
        $token = $this->generateToken($payload);

        // Act
        $response = $this->withToken($token)->getJson('/_test/protected-route');

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'auth_user_id' => $userId,
                'auth_user_email' => $userEmail,
            ]);
    }

    /**
     * Test that authentication fails when the email claim is missing (required claim).
     */
    #[Test]
    public function returns_unauthorized_if_email_claim_is_missing(): void
    {
        // Arrange
        $logger = Mockery::mock();
        $logger->shouldReceive('warning')
            ->once()
            ->with(
                'Invalid JWT token',
                Mockery::on(static fn(array $context): bool => \str_contains($context['error'], "required claim 'email' is missing")
                    && \array_key_exists('event', $context)
                    && \array_key_exists('ip', $context)
                    && \array_key_exists('path', $context)
                    && \array_key_exists('user_agent', $context)),
            );

        Log::shouldReceive('channel')
            ->with('security')
            ->andReturn($logger);

        $payload = ['email' => null];
        $token = $this->generateToken($payload);

        // Act
        $response = $this->withToken($token)->getJson('/_test/protected-route');

        // Assert
        $response->assertStatus(401)->assertJson(['error' => 'Unauthorized']);
    }

    /**
     * Test that empty string token is rejected (kills EmptyStringToNotEmpty mutation on line 27).
     */
    #[Test]
    public function returns_unauthorized_for_empty_string_token(): void
    {
        // Arrange
        $logger = Mockery::mock();
        $logger->shouldReceive('warning')
            ->once()
            ->with(
                'Missing authorization token',
                Mockery::on(static fn(array $context): bool => $context['event'] === 'api.auth.missing_token'
                    && \array_key_exists('ip', $context)
                    && \array_key_exists('path', $context)
                    && \array_key_exists('user_agent', $context)),
            );

        Log::shouldReceive('channel')
            ->with('security')
            ->andReturn($logger);

        // Act: Manually set Authorization header to empty bearer token
        $response = $this->withHeaders(['Authorization' => 'Bearer '])
            ->getJson('/_test/protected-route');

        // Assert
        $response->assertStatus(401)->assertJson(['error' => 'Unauthorized']);
    }

    /**
     * Test that empty string sub claim is rejected (kills EmptyStringToNotEmpty mutation).
     */
    #[Test]
    public function returns_unauthorized_if_sub_claim_is_empty_string(): void
    {
        // Arrange
        $logger = Mockery::mock();
        $logger->shouldReceive('warning')
            ->once()
            ->with(
                'Invalid JWT token',
                Mockery::on(static fn(array $context): bool => \str_contains($context['error'], "claim 'sub' cannot be empty")
                    && \array_key_exists('event', $context)
                    && \array_key_exists('ip', $context)
                    && \array_key_exists('path', $context)
                    && \array_key_exists('user_agent', $context)),
            );

        Log::shouldReceive('channel')
            ->with('security')
            ->andReturn($logger);

        $payload = ['sub' => '']; // Empty string instead of null
        $token = $this->generateToken($payload);

        // Act
        $response = $this->withToken($token)->getJson('/_test/protected-route');

        // Assert
        $response->assertStatus(401)->assertJson(['error' => 'Unauthorized']);
    }

    /**
     * Test that a valid token without MFA (AAL1) is rejected with 403.
     *
     * This ensures backend enforces MFA even if an attacker bypasses the frontend.
     * AAL1 = password only, AAL2 = MFA verified (required).
     */
    #[Test]
    public function returns_forbidden_if_mfa_is_not_verified(): void
    {
        // Arrange
        $logger = Mockery::mock();
        $logger->shouldReceive('warning')
            ->once()
            ->with(
                'MFA not verified - AAL2 required',
                Mockery::on(static fn(array $context): bool => $context['event'] === 'api.auth.mfa_required'
                    && $context['aal_level'] === 'aal1'
                    && \array_key_exists('user_id', $context)
                    && \array_key_exists('email', $context)
                    && \array_key_exists('ip', $context)
                    && \array_key_exists('path', $context)),
            );

        Log::shouldReceive('channel')
            ->with('security')
            ->andReturn($logger);

        // Token with AAL1 (password only, no MFA)
        $token = $this->generateToken(['aal' => 'aal1']);

        // Act
        $response = $this->withToken($token)->getJson('/_test/protected-route');

        // Assert
        $response->assertStatus(403)
            ->assertJson([
                'error' => 'MFA verification required',
                'code' => 'MFA_REQUIRED',
            ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // departments_summary Claim Tests (Issue #95)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Test that departments_summary as comma-separated string is parsed to array.
     * This is the legacy format from Supabase.
     */
    #[Test]
    public function succeeds_with_departments_summary_as_string(): void
    {
        // Arrange
        $payload = [
            'app_metadata' => (object) [
                'departments_summary' => 'Sales,Marketing,Support',
            ],
        ];
        $token = $this->generateToken($payload);

        // Act
        $response = $this->withToken($token)->getJson('/_test/protected-route');

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'departments' => ['Sales', 'Marketing', 'Support'],
            ]);
    }

    /**
     * Test that departments_summary as array is passed through correctly.
     * This is the new format from Supabase.
     */
    #[Test]
    public function succeeds_with_departments_summary_as_array(): void
    {
        // Arrange
        $payload = [
            'app_metadata' => (object) [
                'departments_summary' => ['Sales', 'Marketing', 'Support'],
            ],
        ];
        $token = $this->generateToken($payload);

        // Act
        $response = $this->withToken($token)->getJson('/_test/protected-route');

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'departments' => ['Sales', 'Marketing', 'Support'],
            ]);
    }

    /**
     * Test that empty departments_summary array returns null.
     */
    #[Test]
    public function succeeds_with_empty_departments_summary_array(): void
    {
        // Arrange
        $payload = [
            'app_metadata' => (object) [
                'departments_summary' => [],
            ],
        ];
        $token = $this->generateToken($payload);

        // Act
        $response = $this->withToken($token)->getJson('/_test/protected-route');

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'departments' => null,
            ]);
    }

    /**
     * Test that departments_summary with invalid type (integer) is rejected.
     */
    #[Test]
    public function returns_unauthorized_if_departments_summary_has_invalid_type(): void
    {
        // Arrange
        $logger = Mockery::mock();
        $logger->shouldReceive('warning')
            ->once()
            ->with(
                'Invalid JWT token',
                Mockery::on(static fn(array $context): bool => \str_contains($context['error'], 'expected string or array of strings')
                    && \str_contains($context['error'], 'app_metadata.departments_summary')
                    && \array_key_exists('event', $context)
                    && \array_key_exists('ip', $context)
                    && \array_key_exists('path', $context)),
            );

        Log::shouldReceive('channel')
            ->with('security')
            ->andReturn($logger);

        $payload = [
            'app_metadata' => (object) [
                'departments_summary' => 12345, // Invalid: integer
            ],
        ];
        $token = $this->generateToken($payload);

        // Act
        $response = $this->withToken($token)->getJson('/_test/protected-route');

        // Assert
        $response->assertStatus(401)->assertJson(['error' => 'Unauthorized']);
    }

    /**
     * Test that departments_summary array with non-string elements is rejected.
     */
    #[Test]
    public function returns_unauthorized_if_departments_summary_array_has_non_string_elements(): void
    {
        // Arrange
        $logger = Mockery::mock();
        $logger->shouldReceive('warning')
            ->once()
            ->with(
                'Invalid JWT token',
                Mockery::on(static fn(array $context): bool => \str_contains($context['error'], 'array with non-string elements')
                    && \array_key_exists('event', $context)
                    && \array_key_exists('ip', $context)
                    && \array_key_exists('path', $context)),
            );

        Log::shouldReceive('channel')
            ->with('security')
            ->andReturn($logger);

        $payload = [
            'app_metadata' => (object) [
                'departments_summary' => ['Sales', 123, 'Support'], // Invalid: contains integer
            ],
        ];
        $token = $this->generateToken($payload);

        // Act
        $response = $this->withToken($token)->getJson('/_test/protected-route');

        // Assert
        $response->assertStatus(401)->assertJson(['error' => 'Unauthorized']);
    }
}
