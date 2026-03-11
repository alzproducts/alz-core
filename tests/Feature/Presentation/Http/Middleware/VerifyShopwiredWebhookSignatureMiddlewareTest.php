<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Middleware;

use App\Presentation\Http\Middleware\VerifyShopwiredWebhookSignatureMiddleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * VerifyShopwiredWebhookSignatureMiddleware Tests.
 *
 * Validates the HMAC signature guard and the ShopWired verification token handshake.
 * A test route is registered in setUp() so requests pass through the full middleware stack.
 */
#[CoversClass(VerifyShopwiredWebhookSignatureMiddleware::class)]
final class VerifyShopwiredWebhookSignatureMiddlewareTest extends TestCase
{
    private const string SECRET = 'test-webhook-secret-key';

    /** A minimal valid JSON order webhook body. */
    private const string BODY = '{"event":{"id":1,"topic":"order.updated","subjectId":42,"data":{}}}';

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        \config(['shopwired.webhook_secret' => self::SECRET]);

        Route::post(
            '/_test/webhook',
            static fn(): JsonResponse => new JsonResponse(['ok' => true]),
        )->middleware(VerifyShopwiredWebhookSignatureMiddleware::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Configuration Guard
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_500_when_webhook_secret_is_null(): void
    {
        \config(['shopwired.webhook_secret' => null]);

        $response = $this->postWebhook(self::BODY, signature: 'any-value');

        $response->assertInternalServerError();
        $response->assertJson(['error' => 'Webhook secret not configured']);
    }

    #[Test]
    public function it_returns_500_when_webhook_secret_is_empty_string(): void
    {
        \config(['shopwired.webhook_secret' => '']);

        $response = $this->postWebhook(self::BODY, signature: 'any-value');

        $response->assertInternalServerError();
        $response->assertJson(['error' => 'Webhook secret not configured']);
    }

    /*
    |--------------------------------------------------------------------------
    | Signature Guard
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_returns_403_when_signature_header_is_absent(): void
    {
        $response = $this->postWebhook(self::BODY, signature: null);

        $response->assertForbidden();
        $response->assertJson(['error' => 'Missing signature']);
    }

    #[Test]
    public function it_returns_403_when_signature_does_not_match_body(): void
    {
        $response = $this->postWebhook(self::BODY, signature: 'wrong-signature');

        $response->assertForbidden();
        $response->assertJson(['error' => 'Invalid signature']);
    }

    /*
    |--------------------------------------------------------------------------
    | Happy Path
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_passes_to_next_handler_when_signature_is_valid(): void
    {
        $response = $this->postWebhook(self::BODY, signature: $this->sign(self::BODY));

        $response->assertOk();
        $response->assertJson(['ok' => true]);
    }

    /*
    |--------------------------------------------------------------------------
    | Verification Token Handshake
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_responds_with_hashed_token_during_webhook_registration_handshake(): void
    {
        $payload = '{"verificationToken":"shopwired-challenge-abc"}';
        $expectedHash = \hash_hmac('sha256', 'shopwired-challenge-abc', self::SECRET);

        $response = $this->postWebhook($payload, signature: $this->sign($payload));

        // Short-circuits before reaching the next handler — only the hashed token is returned.
        $response->assertOk();
        $response->assertExactJson(['verificationToken' => $expectedHash]);
    }

    #[Test]
    public function it_does_not_short_circuit_when_verification_token_is_empty_string(): void
    {
        $payload = '{"verificationToken":""}';

        $response = $this->postWebhook($payload, signature: $this->sign($payload));

        // An empty verificationToken must NOT trigger the handshake.
        $response->assertOk();
        $response->assertJson(['ok' => true]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Compute the expected HMAC-SHA256 signature for the given body.
     */
    private function sign(string $body): string
    {
        return \hash_hmac('sha256', $body, self::SECRET);
    }

    /**
     * Make a POST request to the test webhook route.
     *
     * @param string      $body      Raw request body (JSON)
     * @param string|null $signature Value for X-ShopWired-Signature header; null omits the header entirely
     */
    private function postWebhook(string $body, ?string $signature): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        if ($signature !== null) {
            $server['HTTP_X_SHOPWIRED_SIGNATURE'] = $signature;
        }

        return $this->call('POST', '/_test/webhook', [], [], [], $server, $body);
    }
}
