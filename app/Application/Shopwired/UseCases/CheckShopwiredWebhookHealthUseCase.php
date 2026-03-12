<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases;

use App\Application\Contracts\Shopwired\WebhookClientInterface;
use App\Application\Shopwired\DTOs\WebhookDTO;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Notifications\Events\AdminAlertEvent;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;

/**
 * Check ShopWired webhook registrations for health issues.
 *
 * Fetches all registered webhooks and fires an AdminAlertEvent if any
 * are disabled or unverified — conditions that cause silent data sync gaps
 * without triggering any application-level error.
 */
final readonly class CheckShopwiredWebhookHealthUseCase
{
    private const string WEBHOOKS_ADMIN_URL = 'https://admin.myshopwired.uk/business/api-webhooks';

    public function __construct(
        private WebhookClientInterface $webhookClient,
        private LoggerInterface $logger,
        private Dispatcher $eventDispatcher,
    ) {}

    /**
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws AuthenticationExpiredException When credentials invalid/expired
     * @throws ResourceNotFoundException When resource not found
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws InvalidApiResponseException When response parsing fails
     */
    public function execute(): void
    {
        $webhooks = $this->webhookClient->listWebhooks();

        $unhealthy = \array_filter($webhooks, static fn(WebhookDTO $w): bool => ! $w->isHealthy());

        if ($unhealthy === []) {
            $this->logger->info('ShopWired webhook health check passed — all webhooks healthy', [
                'count' => \count($webhooks),
            ]);

            return;
        }

        $this->logger->warning('ShopWired webhook health check failed — unhealthy webhooks detected', [
            'unhealthy_count' => \count($unhealthy),
            'total_count' => \count($webhooks),
        ]);

        $context = [];
        foreach ($unhealthy as $webhook) {
            $context["webhook_{$webhook->id}"] = \sprintf(
                '%s → %s (enabled: %s, verified: %s)',
                $webhook->topic,
                $webhook->address,
                $webhook->enabled ? 'yes' : 'no',
                $webhook->verified ? 'yes' : 'no',
            );
        }

        $this->eventDispatcher->dispatch(new AdminAlertEvent(
            title: 'ShopWired Webhooks Unhealthy',
            message: \sprintf(
                '%d of %d ShopWired webhook(s) are disabled or unverified. Data sync may be silently failing. Re-enable them at: %s',
                \count($unhealthy),
                \count($webhooks),
                self::WEBHOOKS_ADMIN_URL,
            ),
            context: $context,
        ));
    }
}
