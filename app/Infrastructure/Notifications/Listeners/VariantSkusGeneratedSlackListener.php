<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Listeners;

use App\Application\Contracts\ChatNotificationInterface;
use App\Application\Notifications\DTOs\VariantSkuNotificationDataDTO;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Domain\Inventory\Events\VariantSkusGeneratedEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends Slack notification when variant SKUs are generated.
 */
final class VariantSkusGeneratedSlackListener implements ShouldQueue
{
    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        private readonly ChatNotificationInterface $chat,
    ) {}

    /**
     * @throws InvalidConfigurationException When Slack channel is not configured
     * @throws ExternalServiceUnavailableException On Slack delivery failure
     */
    public function handle(VariantSkusGeneratedEvent $event): void
    {
        $this->chat->sendVariantSkusGenerated(new VariantSkuNotificationDataDTO(
            productId: $event->productId,
            productTitle: $event->productTitle,
            created: $event->created,
            skipped: $event->skipped,
            failed: $event->failed,
            createdVariants: $event->createdVariants,
        ));
    }

    public function failed(VariantSkusGeneratedEvent $event, Throwable $e): void
    {
        Log::error('Could not send variant SKU generation notification', [
            'product_id' => $event->productId,
            'exception' => $e->getMessage(),
        ]);
    }
}
