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
     * @return TestResponse<Response>
     */
    private function makeRequest(?string $user, ?string $pass): TestResponse
    {
        $headers = [];
        if ($user !== null || $pass !== null) {
            $token = \base64_encode(($user ?? '') . ':' . ($pass ?? ''));
            $headers['Authorization'] = 'Basic ' . $token;
        }

        return $this->withHeaders($headers)->get('/_test/protected-route');
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
     * Test that the middleware throws an exception if the next handler
     * does not return a valid Response object, upholding the framework contract.
     *
     * @test
     */
    public function throws_logic_exception_if_next_middleware_returns_invalid_response(): void
    {
        // Arrange
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Middleware pipeline must return Response instance');

        $middleware = new HorizonBasicAuth();
        $request = new Request();

        // A closure that violates the contract by not returning a Response.
        $next = static fn(Request $req) => null;

        \config([
            'horizon.auth.username' => self::USER,
            'horizon.auth.password' => self::PASS,
        ]);

        // Set valid credentials on the request directly to bypass auth check
        // and focus on the response validation logic.
        $request->headers->set('PHP_AUTH_USER', self::USER);
        $request->headers->set('PHP_AUTH_PW', self::PASS);

        // Act: Handle the request. The exception is the assertion.
        $middleware->handle($request, $next);
    }
}
