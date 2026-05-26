<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Middleware;

use App\Presentation\Http\Middleware\VerifyTwilioWebhookSignatureMiddleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(VerifyTwilioWebhookSignatureMiddleware::class)]
final class VerifyTwilioWebhookSignatureMiddlewareTest extends TestCase
{
    private const string AUTH_TOKEN = 'test-twilio-auth-token-1234567890';

    private const string TEST_URL = 'http://localhost/_test/twilio-webhook';

    /**
     * Twilio statusCallback POST params (PascalCase keys, intentionally unsorted).
     *
     * @var array<string, string>
     */
    private const array PARAMS = [
        'To' => '+441234567890',
        'From' => '+447900123456',
        'CallSid' => 'CA1234567890abcdef1234567890abcdef',
    ];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        \config(['call-tracking.twilio_auth_token' => self::AUTH_TOKEN]);

        Route::post(
            '/_test/twilio-webhook',
            static fn(): JsonResponse => new JsonResponse(['ok' => true]),
        )->middleware(VerifyTwilioWebhookSignatureMiddleware::class);
    }

    #[Test]
    public function it_returns_500_when_auth_token_is_null(): void
    {
        \config(['call-tracking.twilio_auth_token' => null]);

        $response = $this->postWebhook(self::PARAMS, signature: 'any-value');

        $response->assertInternalServerError();
        $response->assertJsonPath('error.type', 'server_error');
        $response->assertJsonPath('error.message', 'Twilio auth token not configured');
    }

    #[Test]
    public function it_returns_500_when_auth_token_is_empty_string(): void
    {
        \config(['call-tracking.twilio_auth_token' => '']);

        $response = $this->postWebhook(self::PARAMS, signature: 'any-value');

        $response->assertInternalServerError();
        $response->assertJsonPath('error.type', 'server_error');
    }

    #[Test]
    public function it_returns_403_when_signature_header_is_absent(): void
    {
        $response = $this->postWebhook(self::PARAMS, signature: null);

        $response->assertForbidden();
        $response->assertJsonPath('error.type', 'forbidden');
        $response->assertJsonPath('error.message', 'Missing signature');
    }

    #[Test]
    public function it_returns_403_when_signature_does_not_match(): void
    {
        $response = $this->postWebhook(self::PARAMS, signature: 'wrong-signature');

        $response->assertForbidden();
        $response->assertJsonPath('error.type', 'forbidden');
        $response->assertJsonPath('error.message', 'Invalid signature');
    }

    #[Test]
    public function it_passes_to_next_handler_when_signature_is_valid(): void
    {
        $signature = self::computeSignature(self::TEST_URL, self::PARAMS, self::AUTH_TOKEN);

        $response = $this->postWebhook(self::PARAMS, signature: $signature);

        $response->assertOk();
        $response->assertJson(['ok' => true]);
    }

    /**
     * Twilio HMAC-SHA1 algorithm (mirror of middleware implementation): fullUrl
     * concatenated with alphabetically-sorted `key.value` pairs, base64-encoded.
     *
     * @param array<string, string> $params
     */
    private static function computeSignature(string $url, array $params, string $authToken): string
    {
        \ksort($params);
        $data = $url;
        foreach ($params as $key => $value) {
            $data .= $key . $value;
        }

        return \base64_encode(\hash_hmac('sha1', $data, $authToken, true));
    }

    /**
     * @param array<string, string> $params
     */
    private function postWebhook(array $params, ?string $signature): TestResponse
    {
        $server = [];
        if ($signature !== null) {
            $server['HTTP_X_TWILIO_SIGNATURE'] = $signature;
        }

        return $this->call('POST', '/_test/twilio-webhook', $params, [], [], $server);
    }
}
