<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Api;

use App\Application\Shopwired\DTOs\WebhookDTO;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Infrastructure\Shopwired\Clients\WebhookClient;
use App\Infrastructure\Shopwired\ShopwiredConfig;
use App\Infrastructure\Shopwired\ShopwiredHttpTransport;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * WebhookClient Feature Tests.
 *
 * Tests the WebhookClient's ability to parse API responses and translate errors.
 */
#[CoversClass(WebhookClient::class)]
final class WebhookClientTest extends TestCase
{
    private WebhookClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $config = new ShopwiredConfig(
            apiKey: 'test-key',
            apiSecret: 'test-secret',
        );

        $this->client = new WebhookClient(new ShopwiredHttpTransport($config));
    }

    #[Test]
    public function it_returns_parsed_webhook_list_on_success(): void
    {
        Http::fake([
            '*/webhooks*' => Http::response([
                [
                    'id' => 1,
                    'topic' => 'order.created',
                    'url' => 'https://example.com/webhooks/orders',
                    'enabled' => true,
                    'verified' => true,
                ],
                [
                    'id' => 2,
                    'topic' => 'product.updated',
                    'url' => 'https://example.com/webhooks/products',
                    'enabled' => false,
                    'verified' => true,
                ],
            ], 200),
        ]);

        $webhooks = $this->client->listWebhooks();

        $this->assertCount(2, $webhooks);

        $this->assertInstanceOf(WebhookDTO::class, $webhooks[0]);
        $this->assertSame(1, $webhooks[0]->id);
        $this->assertSame('order.created', $webhooks[0]->topic);
        $this->assertTrue($webhooks[0]->enabled);
        $this->assertTrue($webhooks[0]->verified);

        $this->assertSame(2, $webhooks[1]->id);
        $this->assertFalse($webhooks[1]->enabled);
    }

    #[Test]
    public function it_returns_empty_array_when_no_webhooks_registered(): void
    {
        Http::fake(['*/webhooks*' => Http::response([], 200)]);

        $webhooks = $this->client->listWebhooks();

        $this->assertSame([], $webhooks);
    }

    #[Test]
    public function it_throws_authentication_exception_on_401(): void
    {
        Http::fake(['*/webhooks*' => Http::response(['error' => 'Unauthorized'], 401)]);

        $this->expectException(AuthenticationExpiredException::class);

        $this->client->listWebhooks();
    }

    #[Test]
    public function it_throws_service_unavailable_exception_on_500(): void
    {
        Http::fake(['*/webhooks*' => Http::response(['error' => 'Server Error'], 500)]);

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->client->listWebhooks();
    }
}
