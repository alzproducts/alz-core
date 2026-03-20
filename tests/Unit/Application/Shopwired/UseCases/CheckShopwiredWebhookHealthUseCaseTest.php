<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Shopwired\UseCases;

use App\Application\Contracts\Shopwired\WebhookClientInterface;
use App\Application\Shopwired\DTOs\WebhookDTO;
use App\Application\Shopwired\UseCases\CheckShopwiredWebhookHealthUseCase;
use App\Domain\Notifications\Events\ManagerAlertEvent;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * CheckShopwiredWebhookHealthUseCase Unit Tests.
 *
 * Tests the webhook health check business logic:
 * - No action when all webhooks are healthy
 * - ManagerAlertEvent fired when webhooks are disabled or unverified
 * - Correct counts and context keys in the alert
 */
#[CoversClass(CheckShopwiredWebhookHealthUseCase::class)]
final class CheckShopwiredWebhookHealthUseCaseTest extends TestCase
{
    private WebhookClientInterface&MockInterface $webhookClient;

    private LoggerInterface&MockInterface $logger;

    private CheckShopwiredWebhookHealthUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([ManagerAlertEvent::class]);

        $this->webhookClient = Mockery::mock(WebhookClientInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->useCase = new CheckShopwiredWebhookHealthUseCase(
            webhookClient: $this->webhookClient,
            logger: $this->logger,
            eventDispatcher: \app(Dispatcher::class),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | All Healthy
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_logs_info_and_fires_no_event_when_all_webhooks_are_healthy(): void
    {
        $this->webhookClient
            ->shouldReceive('listWebhooks')
            ->once()
            ->andReturn([
                $this->makeWebhook(1, 'order.created', enabled: true, verified: true),
                $this->makeWebhook(2, 'order.deleted', enabled: true, verified: true),
            ]);

        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('ShopWired webhook health check passed — all webhooks healthy', ['count' => 2]);

        $this->useCase->execute();

        Event::assertNotDispatched(ManagerAlertEvent::class);
    }

    #[Test]
    public function it_logs_info_and_fires_no_event_when_no_webhooks_are_registered(): void
    {
        $this->webhookClient
            ->shouldReceive('listWebhooks')
            ->once()
            ->andReturn([]);

        $this->logger
            ->shouldReceive('info')
            ->once()
            ->with('ShopWired webhook health check passed — all webhooks healthy', ['count' => 0]);

        $this->useCase->execute();

        Event::assertNotDispatched(ManagerAlertEvent::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Unhealthy Webhooks
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_fires_admin_alert_when_a_webhook_is_disabled(): void
    {
        $this->webhookClient
            ->shouldReceive('listWebhooks')
            ->once()
            ->andReturn([
                $this->makeWebhook(1, 'order.created', enabled: false, verified: true),
                $this->makeWebhook(2, 'order.deleted', enabled: true, verified: true),
            ]);

        $this->logger->shouldReceive('warning')->once();

        $this->useCase->execute();

        Event::assertDispatched(ManagerAlertEvent::class);
    }

    #[Test]
    public function it_fires_admin_alert_when_a_webhook_is_unverified(): void
    {
        $this->webhookClient
            ->shouldReceive('listWebhooks')
            ->once()
            ->andReturn([
                $this->makeWebhook(1, 'order.created', enabled: true, verified: false),
            ]);

        $this->logger->shouldReceive('warning')->once();

        $this->useCase->execute();

        Event::assertDispatched(ManagerAlertEvent::class);
    }

    #[Test]
    public function it_reports_correct_unhealthy_and_total_counts_in_alert_message(): void
    {
        $this->webhookClient
            ->shouldReceive('listWebhooks')
            ->once()
            ->andReturn([
                $this->makeWebhook(1, 'order.created', enabled: false, verified: true),
                $this->makeWebhook(2, 'order.deleted', enabled: true, verified: false),
                $this->makeWebhook(3, 'product.created', enabled: true, verified: true),
            ]);

        $this->logger->shouldReceive('warning')->once();

        $this->useCase->execute();

        Event::assertDispatched(
            ManagerAlertEvent::class,
            static fn(ManagerAlertEvent $e): bool => $e->message === '2 of 3 ShopWired webhook(s) are disabled or unverified. Data sync may be silently failing. Re-enable them at: https://admin.myshopwired.uk/business/api-webhooks'
                && $e->title === 'ShopWired Webhooks Unhealthy',
        );
    }

    #[Test]
    public function it_includes_unhealthy_webhook_ids_in_alert_context(): void
    {
        $this->webhookClient
            ->shouldReceive('listWebhooks')
            ->once()
            ->andReturn([
                $this->makeWebhook(42, 'order.created', enabled: false, verified: true),
                $this->makeWebhook(99, 'product.deleted', enabled: true, verified: true),
            ]);

        $this->logger->shouldReceive('warning')->once();

        $this->useCase->execute();

        Event::assertDispatched(
            ManagerAlertEvent::class,
            static fn(ManagerAlertEvent $e): bool => \array_key_exists('webhook_42', $e->context)
                && ! \array_key_exists('webhook_99', $e->context),
        );
    }

    #[Test]
    public function it_logs_warning_with_correct_counts_before_firing_alert(): void
    {
        $this->webhookClient
            ->shouldReceive('listWebhooks')
            ->once()
            ->andReturn([
                $this->makeWebhook(1, 'order.created', enabled: false, verified: true),
                $this->makeWebhook(2, 'order.deleted', enabled: true, verified: true),
            ]);

        $this->logger
            ->shouldReceive('warning')
            ->once()
            ->with(
                'ShopWired webhook health check failed — unhealthy webhooks detected',
                ['unhealthy_count' => 1, 'total_count' => 2],
            );

        $this->useCase->execute();
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function makeWebhook(
        int $id,
        string $topic,
        bool $enabled,
        bool $verified,
        string $address = 'https://example.com/webhooks',
    ): WebhookDTO {
        return new WebhookDTO(
            id: $id,
            topic: $topic,
            address: $address,
            enabled: $enabled,
            verified: $verified,
        );
    }
}
