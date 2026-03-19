<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Notifications;

use App\Domain\Catalog\Product\ValueObjects\ProductRetailPricing;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Catalog\Product\ValueObjects\SkuPriceChange;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Notifications\Slack\ProductPricingUpdatedNotification;
use App\Infrastructure\Notifications\SlackChatNotificationClient;
use Exception;
use Illuminate\Contracts\Notifications\Dispatcher as NotificationDispatcher;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Integration tests for SlackChatNotificationClient.
 *
 * Per TestingStrategy.md: 1-2 integration tests at the notification boundary.
 * Tests config resolution, notification dispatch, and exception translation.
 */
#[CoversClass(SlackChatNotificationClient::class)]
final class SlackChatNotificationClientTest extends TestCase
{
    private SlackChatNotificationClient $client;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new SlackChatNotificationClient(
            $this->app->make(NotificationDispatcher::class),
        );
    }

    #[Test]
    public function it_dispatches_notification_when_channel_is_configured(): void
    {
        Notification::fake();
        \config()->set('services.slack.notifications.verbose_channel', '#test-verbose');

        // Resolve after fake so client gets the fake dispatcher
        $client = new SlackChatNotificationClient(
            $this->app->make(NotificationDispatcher::class),
        );

        $client->sendPriceUpdateAlert(IntId::from(123), [
            new SkuPriceChange(
                sku: Sku::fromTrusted('WEB-100'),
                previousPrices: new ProductRetailPricing(Money::inclusive(24.99)),
                newPrices: new ProductRetailPricing(Money::inclusive(19.99)),
            ),
        ]);

        Notification::assertSentOnDemand(ProductPricingUpdatedNotification::class);
    }

    #[Test]
    public function it_throws_invalid_configuration_when_channel_is_missing(): void
    {
        \config()->set('services.slack.notifications.verbose_channel', '');

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage("Slack channel 'verbose_channel' is not configured");

        $this->client->sendPriceUpdateAlert(IntId::from(123), []);
    }

    #[Test]
    public function it_translates_transport_failures_to_domain_exception(): void
    {
        \config()->set('services.slack.notifications.verbose_channel', '#test');

        $mockDispatcher = Mockery::mock(NotificationDispatcher::class);
        $mockDispatcher->shouldReceive('send')->andThrow(new Exception('Connection refused'));

        $client = new SlackChatNotificationClient($mockDispatcher);

        try {
            $client->sendPriceUpdateAlert(IntId::from(123), []);
            $this->fail('Expected ExternalServiceUnavailableException');
        } catch (ExternalServiceUnavailableException $e) {
            $this->assertSame('Slack', $e->serviceName);
            $this->assertSame('Connection refused', $e->getPrevious()->getMessage());
        }
    }
}
