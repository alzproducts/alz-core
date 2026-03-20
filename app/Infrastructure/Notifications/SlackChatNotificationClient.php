<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Contracts\ChatNotificationInterface;
use App\Domain\Catalog\Product\ValueObjects\SkuPriceChange;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmission;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Notifications\Slack\AdminAlertNotification;
use App\Infrastructure\Notifications\Slack\ContactFormFailedNotification;
use App\Infrastructure\Notifications\Slack\ContactFormProcessedNotification;
use App\Infrastructure\Notifications\Slack\ProductPricingUpdatedNotification;
use App\Infrastructure\Notifications\Slack\VariantSkusGeneratedNotification;
use DateTimeImmutable;
use Exception;
use Illuminate\Contracts\Notifications\Dispatcher as NotificationDispatcher;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Slack implementation of chat notifications.
 *
 * Encapsulates all Laravel Notification mechanics: message construction,
 * BlockKit formatting, channel resolution from config, and exception
 * translation. Callers pass domain data only.
 */
final readonly class SlackChatNotificationClient implements ChatNotificationInterface
{
    private const string CONFIG_PREFIX = 'services.slack.notifications.';

    private const string CHANNEL_DEFAULT = 'channel';
    private const string CHANNEL_VERBOSE = 'verbose_channel';
    private const string CHANNEL_ADMIN_ALERTS = 'admin_alerts_channel';
    private const string CHANNEL_MANAGER_ALERTS = 'manager_alerts_channel';
    private const string SLACK_DRIVER = 'slack';

    public function __construct(
        private NotificationDispatcher $dispatcher,
    ) {}

    /**
     * @throws InvalidConfigurationException When target channel is not configured
     * @throws ExternalServiceUnavailableException On delivery failure
     */
    public function sendAdminAlert(
        string $title,
        string $message,
        array $context,
        DateTimeImmutable $firedAt,
    ): void {
        $this->send(
            self::CHANNEL_ADMIN_ALERTS,
            new AdminAlertNotification($title, $message, $context, $firedAt),
        );
    }

    /**
     * @throws InvalidConfigurationException When target channel is not configured
     * @throws ExternalServiceUnavailableException On delivery failure
     */
    public function sendManagerAlert(
        string $title,
        string $message,
        array $context,
        DateTimeImmutable $firedAt,
    ): void {
        $this->send(
            self::CHANNEL_MANAGER_ALERTS,
            new AdminAlertNotification($title, $message, $context, $firedAt),
        );
    }

    /**
     * @param list<SkuPriceChange> $priceChanges
     *
     * @throws ExternalServiceUnavailableException On delivery failure
     */
    public function sendPriceUpdateAlert(
        IntId $productId,
        array $priceChanges,
        ?string $productTitle = null,
        ?string $productUrl = null,
    ): void {
        $this->send(
            self::CHANNEL_VERBOSE,
            new ProductPricingUpdatedNotification(
                productId: $productId->value,
                priceChanges: $priceChanges,
                productTitle: $productTitle,
                productUrl: $productUrl,
            ),
        );
    }

    /**
     * @throws InvalidConfigurationException When target channel is not configured
     * @throws ExternalServiceUnavailableException On delivery failure
     */
    public function sendContactFormProcessed(
        IntId $conversationId,
        string $customerName,
        string $customerEmail,
    ): void {
        $this->send(
            self::CHANNEL_VERBOSE,
            new ContactFormProcessedNotification(
                conversationId: $conversationId->value,
                customerName: $customerName,
                customerEmail: $customerEmail,
            ),
        );
    }

    /**
     * @throws InvalidConfigurationException When target channel is not configured
     * @throws ExternalServiceUnavailableException On delivery failure
     */
    public function sendContactFormFailed(
        ContactSubmission $submission,
        string $submissionId,
        string $errorMessage,
        ?bool $emailValid,
    ): void {
        $this->send(
            self::CHANNEL_DEFAULT,
            new ContactFormFailedNotification(
                submission: $submission,
                submissionId: $submissionId,
                errorMessage: $errorMessage,
                emailValid: $emailValid,
            ),
        );
    }

    /**
     * @param list<string> $createdVariants
     *
     * @throws ExternalServiceUnavailableException On delivery failure
     */
    public function sendVariantSkusGenerated(
        int $productId,
        string $productTitle,
        int $created,
        int $skipped,
        int $failed,
        array $createdVariants,
    ): void {
        $this->send(
            self::CHANNEL_DEFAULT,
            new VariantSkusGeneratedNotification(
                productId: $productId,
                productTitle: $productTitle,
                created: $created,
                skipped: $skipped,
                failed: $failed,
                createdVariants: $createdVariants,
            ),
        );
    }

    /**
     * Resolve channel from config and deliver the notification.
     *
     * Throws InvalidConfigurationException when the channel is not configured.
     * Translates transport exceptions to domain exceptions per Infrastructure rules.
     *
     * @throws InvalidConfigurationException When channel config is missing or empty
     * @throws ExternalServiceUnavailableException On Slack API/transport failure
     */
    private function send(string $configKey, Notification $notification): void
    {
        $channel = \config(self::CONFIG_PREFIX . $configKey);
        if (! \is_string($channel) || $channel === '') {
            throw new InvalidConfigurationException(
                self::CONFIG_PREFIX . $configKey,
                "Slack channel '{$configKey}' is not configured. Set the corresponding env variable.",
            );
        }

        try {
            $notifiable = (new AnonymousNotifiable())->route(self::SLACK_DRIVER, $channel);
            $this->dispatcher->send($notifiable, $notification);
        } catch (Exception $e) {
            Log::error('Slack notification delivery failed', [
                'channel' => $configKey,
                'notification' => $notification::class,
                'exception' => $e->getMessage(),
            ]);

            throw new ExternalServiceUnavailableException('Slack', previous: $e);
        }
    }
}
