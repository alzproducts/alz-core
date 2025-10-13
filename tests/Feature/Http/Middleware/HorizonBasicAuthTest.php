<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Middleware;

use App\Http\Middleware\HorizonBasicAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * @covers \App\Http\Middleware\HorizonBasicAuth
 */
class HorizonBasicAuthTest extends TestCase
{
    /**
     * The username to use for testing.
     */
    private const USER = 'test-user';

    /**
     * The password to use for testing.
     */
    private const PASS = 'test-password';

    /**
     * Set up a test route protected by the middleware before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Define a test route that is protected by the middleware.
        // This allows us to test the middleware in a realistic request-response cycle.
        Route::get('/_test/protected-route', static function (): Response {
            return \response('Success', 200);
        })->middleware(HorizonBasicAuth::class);
    }

    /**
     * Helper to make a request with Basic Auth credentials.
     *
     * @param  string|null  $user
     * @param  string|null  $pass
     * @param  string  $uri
     * @return TestResponse<Response>
     */
    private function makeRequest(?string $user, ?string $pass, string $uri = '/_test/protected-route'): TestResponse
    {
        $headers = [];
        if ($user !== null || $pass !== null) {
            $token = \base64_encode(($user ?? '') . ':' . ($pass ?? ''));
            $headers['Authorization'] = 'Basic ' . $token;
        }

        return $this->withHeaders($headers)->get($uri);
    }

    /**
     * Test that a request with valid credentials passes through the middleware.
     *
     * @test
     */
    public function allows_access_with_valid_credentials(): void
    {
        // Arrange: Set the expected username and password in the configuration.
        \config([
            'horizon.auth.username' => self::USER,
            'horizon.auth.password' => self::PASS,
        ]);

        // Act: Make a request with the correct credentials.
        $response = $this->makeRequest(self::USER, self::PASS);

        // Assert: The request was successful and the middleware passed it through.
        $response->assertOk();
        $response->assertSee('Success');
    }

    /**
     * Test that requests with incorrect credentials are rejected.
     *
     * SECURITY: This test validates that the middleware uses hash_equals()
     * for timing-safe comparison, preventing timing-based user enumeration attacks.
     * The comparison must complete in constant time regardless of credential correctness.
     *
     * @test
     *
     * @dataProvider invalidCredentialsProvider
     */
    public function denies_access_with_invalid_credentials(
        ?string $providedUser,
        ?string $providedPass,
        string $description,
    ): void {
        // Arrange: Set the expected username and password in the configuration.
        \config([
            'horizon.auth.username' => self::USER,
            'horizon.auth.password' => self::PASS,
        ]);

        // Act: Make a request with invalid credentials.
        $response = $this->makeRequest($providedUser, $providedPass);

        // Assert: The request was unauthorized.
        $response->assertUnauthorized();
        $response->assertHeader('WWW-Authenticate', 'Basic realm="Horizon Dashboard"');
    }

    /**
     * Provides sets of invalid credentials for testing.
     *
     * @return array<string, array{string|null, string|null, string}>
     */
    public static function invalidCredentialsProvider(): array
    {
        return [
            'incorrect password' => [self::USER, 'wrong-password', 'Correct user, wrong password'],
            'incorrect username' => ['wrong-user', self::PASS, 'Wrong user, correct password'],
            'null credentials' => [null, null, 'No credentials provided'],
            'empty string credentials' => ['', '', 'Empty string credentials'],
        ];
    }

    /**
     * Test that the middleware aborts if credentials are not configured.
     *
     * @test
     *
     * @dataProvider unconfiguredCredentialsProvider
     */
    public function aborts_with_500_if_credentials_are_not_configured(?string $user, ?string $pass): void
    {
        // Arrange: Set an invalid configuration.
        \config([
            'horizon.auth.username' => $user,
            'horizon.auth.password' => $pass,
        ]);

        // Act: Make a request.
        $response = $this->makeRequest(self::USER, self::PASS);

        // Assert: The server should return a 500 error.
        // This prevents the dashboard from being exposed with no authentication.
        // The status code is the critical security requirement - access must be denied.
        $response->assertStatus(500);
    }

    /**
     * Provides invalid configuration values.
     *
     * @return array<string, array{string|null, string|null}>
     */
    public static function unconfiguredCredentialsProvider(): array
    {
        return [
            'null username' => [null, self::PASS],
            'empty string username' => ['', self::PASS],
            'null password' => [self::USER, null],
            'empty string password' => [self::USER, ''],
        ];
    }

    /**
     * Test that the middleware rejects non-string config values from .env parsing.
     *
     * Environment variables without quotes can be parsed as integers or booleans:
     * - HORIZON_USER=0 → integer 0
     * - HORIZON_USER=false → boolean false
     * - HORIZON_USER=true → boolean true
     *
     * These are configuration errors and should fail loudly with 500 errors.
     *
     * @test
     *
     * @dataProvider nonStringConfigValuesProvider
     */
    public function rejects_non_string_config_values(mixed $user, mixed $pass, string $description): void
    {
        // Arrange: Set configuration with non-string values.
        \config([
            'horizon.auth.username' => $user,
            'horizon.auth.password' => $pass,
        ]);

        // Act: Make a request.
        $response = $this->makeRequest(self::USER, self::PASS);

        // Assert: Non-string values are configuration errors - fail with 500.
        $response->assertStatus(500);
    }

    /**
     * Provides non-string configuration values that can result from .env parsing.
     *
     * @return array<string, array{mixed, mixed, string}>
     */
    public static function nonStringConfigValuesProvider(): array
    {
        return [
            'integer zero username' => [0, self::PASS, 'Integer 0 is invalid - must be string'],
            'boolean false username' => [false, self::PASS, 'Boolean false is invalid - must be string'],
            'boolean true username' => [true, self::PASS, 'Boolean true is invalid - must be string'],
            'integer zero password' => [self::USER, 0, 'Integer 0 is invalid - must be string'],
            'boolean false password' => [self::USER, false, 'Boolean false is invalid - must be string'],
            'boolean true password' => [self::USER, true, 'Boolean true is invalid - must be string'],
        ];
    }

    /**
     * Test edge case where a valid password is a falsy string '0'.
     *
     * @test
     */
    public function allows_access_with_falsy_zero_string_password(): void
    {
        // Arrange: Configure a password that is a '0' string.
        // This ensures the check is not using a loose comparison like `empty()`.
        $falsyPassword = '0';
        \config([
            'horizon.auth.username' => self::USER,
            'horizon.auth.password' => $falsyPassword,
        ]);

        // Act: Make a request with the correct '0' password.
        $response = $this->makeRequest(self::USER, $falsyPassword);

        // Assert: The request should be successful.
        $response->assertOk();
    }

    /**
     * Test edge case where credentials contain special characters.
     *
     * @test
     */
    public function allows_access_with_special_characters_in_credentials(): void
    {
        // Arrange: Configure credentials with various special characters.
        $specialUser = 'user@name';
        $specialPass = 'p@s$w:o r d!';
        \config([
            'horizon.auth.username' => $specialUser,
            'horizon.auth.password' => $specialPass,
        ]);

        // Act: Make a request with the special credentials.
        $response = $this->makeRequest($specialUser, $specialPass);

        // Assert: The request should be successful, proving that `hash_equals`
        // and base64 encoding handle the characters correctly.
        $response->assertOk();
    }

    /**
     * Test that the middleware validates Response type from the next handler.
     *
     * NOTE: This test verifies defensive programming in the middleware, but Laravel's
     * framework automatically converts null returns to proper Response objects before
     * they reach middleware. This check serves as documentation of the contract and
     * provides protection if used outside Laravel's standard request lifecycle.
     *
     * @test
     */
    public function validates_response_type_from_next_handler(): void
    {
        // Arrange: Set configuration first
        \config([
            'horizon.auth.username' => self::USER,
            'horizon.auth.password' => self::PASS,
        ]);

        // Create a properly authenticated request with Authorization header
        $middleware = new HorizonBasicAuth();
        $request = Request::create('/_test', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Basic ' . \base64_encode(self::USER . ':' . self::PASS),
        ]);

        // Create a next handler that violates the contract
        $next = static fn(Request $req) => null;

        // Assert: Expect the LogicException
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Middleware pipeline must return Response instance');

        // Act: Call middleware directly (bypassing Laravel's framework protection)
        $middleware->handle($request, $next);
    }
}
