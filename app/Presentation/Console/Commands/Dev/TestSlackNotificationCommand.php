<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands\Dev;

use App\Application\Contracts\ChatNotificationInterface;
use App\Application\Notifications\DTOs\PriceUpdateAlertDataDTO;
use App\Application\Notifications\DTOs\VariantSkuNotificationDataDTO;
use App\Domain\Catalog\Product\ValueObjects\ProductRetailPricing;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Catalog\Product\ValueObjects\SkuPriceChange;
use App\Domain\ContactSubmission\Enums\ContactReason;
use App\Domain\ContactSubmission\ValueObjects\ConsentStatus;
use App\Domain\ContactSubmission\ValueObjects\ContactFormData;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmission;
use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\ContactSubmission\ValueObjects\SelectedProduct;
use App\Domain\ContactSubmission\ValueObjects\SubmissionContext;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Notifications\Dispatcher as NotificationDispatcher;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\BlockKit\Blocks\ContextBlock;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage;
use InvalidArgumentException;
use Throwable;

/**
 * Test Slack notification connectivity.
 *
 * Two modes:
 * - Basic: sends a simple test message to verify Slack credentials (raw connectivity)
 * - --notification: sends a real notification via ChatNotificationInterface (end-to-end)
 */
final class TestSlackNotificationCommand extends Command
{
    protected $signature = 'slack:test
        {channel? : The Slack channel to send to (e.g., #dev-notifications)}
        {message? : Custom message to send}
        {--notification= : Test via ChatNotificationInterface (admin-alert, contact-failed, contact-processed, variant-skus, pricing-updated)}';

    protected $description = 'Send a test notification to Slack';

    public function __construct(
        private readonly ChatNotificationInterface $chat,
        private readonly NotificationDispatcher $dispatcher,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        /** @var string|null $notificationType */
        $notificationType = $this->option('notification');

        if ($notificationType !== null) {
            return $this->handleNotification($notificationType);
        }

        return $this->handleBasicTest();
    }

    /**
     * Basic connectivity test — sends a simple message directly to Slack.
     *
     * Bypasses ChatNotificationInterface to isolate Slack credential issues.
     */
    private function handleBasicTest(): int
    {
        /** @var string|null $channel */
        $channel = $this->argument('channel');
        /** @var string|null $message */
        $message = $this->argument('message');

        $channel ??= \config('services.slack.notifications.channel');
        $message ??= 'Test message from alz-core at ' . \now()->toDateTimeString();

        if (! \is_string($channel) || $channel === '') {
            $this->error('No channel specified and SLACK_BOT_USER_DEFAULT_CHANNEL is not set.');
            $this->line('  Usage: php artisan slack:test "#channel-name"');
            $this->line('  Or set SLACK_BOT_USER_DEFAULT_CHANNEL in .env');

            return self::FAILURE;
        }

        $token = \config('services.slack.notifications.bot_user_oauth_token');

        if (! \is_string($token) || $token === '') {
            $this->error('SLACK_BOT_USER_OAUTH_TOKEN is not configured.');
            $this->line('  1. Create a Slack app at: https://api.slack.com/apps');
            $this->line('  2. Add OAuth scopes: chat:write, chat:write.public');
            $this->line('  3. Install to workspace and copy the Bot User OAuth Token');
            $this->line('  4. Add to .env: SLACK_BOT_USER_OAUTH_TOKEN=xoxb-your-token');

            return self::FAILURE;
        }

        $this->info("Sending test notification to {$channel}...");

        try {
            $notifiable = (new AnonymousNotifiable())->route('slack', $channel);
            $this->dispatcher->send($notifiable, $this->buildBasicNotification($message, $channel));

            $this->info('✓ Notification sent successfully');
            $this->line("  Channel: {$channel}");
            $this->line("  Message: {$message}");

            return self::SUCCESS;
        } catch (Throwable $e) { // @ignoreException - connectivity test: report failure to user
            $this->error('✗ Failed to send notification');
            $this->line("  Error: {$e->getMessage()}");
            $this->newLine();
            $this->line('Troubleshooting:');
            $this->line('  1. Verify SLACK_BOT_USER_OAUTH_TOKEN is correct');
            $this->line('  2. Ensure bot has chat:write scope');
            $this->line('  3. For private channels, add chat:write.public scope or invite bot to channel');

            return self::FAILURE;
        }
    }

    /**
     * End-to-end test — sends a notification via ChatNotificationInterface.
     *
     * Tests the full stack: interface → SlackChatNotificationClient → Slack API.
     */
    private function handleNotification(string $type): int
    {
        $this->info("Sending {$type} notification via ChatNotificationInterface...");

        try {
            match ($type) {
                'admin-alert' => $this->sendAdminAlert(),
                'contact-failed' => $this->sendContactFormFailed(),
                'contact-processed' => $this->sendContactFormProcessed(),
                'variant-skus' => $this->sendVariantSkusGenerated(),
                'pricing-updated' => $this->sendPricingUpdated(),
                default => throw new InvalidArgumentException("Unknown notification type: {$type}"),
            };

            $this->info('✓ Notification sent successfully');
            $this->line("  Type: {$type}");

            return self::SUCCESS;
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());
            $this->line('  Available: admin-alert, contact-failed, contact-processed, variant-skus, pricing-updated');

            return self::FAILURE;
        } catch (Throwable $e) { // @ignoreException - connectivity test: report failure to user
            $this->error('✗ Failed to send notification');
            $this->line("  Error: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * @throws ExternalServiceUnavailableException
     */
    private function sendAdminAlert(): void
    {
        $this->chat->sendAdminAlert(
            title: 'Test Admin Alert',
            message: 'This is a test admin alert from `slack:test --notification=admin-alert`.',
            context: [
                'environment' => \app()->environment(),
                'triggered_by' => 'slack:test command',
            ],
            firedAt: new DateTimeImmutable(),
        );
    }

    /**
     * @throws ExternalServiceUnavailableException
     */
    private function sendContactFormFailed(): void
    {
        $submittedAt = new DateTimeImmutable('-15 minutes');

        $submission = new ContactSubmission(
            form: new ContactFormData(
                name: 'John Smith',
                email: 'john.smith@example.com',
                reason: ContactReason::MyOrderDelivery,
                message: "Hi, I ordered a mobility scooter 3 days ago and the tracking still shows 'pending'. Can you please check on this? My elderly mother really needs it urgently.\n\nOrder was placed on Monday.",
                phone: '07700 900123',
                orderNumber: 'ALZ-12345',
                deliveryPostcode: 'SW1A 1AA',
            ),
            consent: ConsentStatus::denied(),
            attribution: MarketingAttribution::empty(),
            context: new SubmissionContext(
                clientTimestamp: new DateTimeImmutable(),
                ipAddress: '192.168.1.1',
                pageUrl: 'https://alzproducts.co.uk/contact',
            ),
            product: new SelectedProduct(
                productId: IntId::from(123456),
                sku: null,
                title: 'Folding Mobility Scooter - Blue',
                price: '£899.00',
            ),
            submittedAt: $submittedAt,
        );

        $this->chat->sendContactFormFailed(
            submission: $submission,
            submissionId: 'test-' . \now()->format('YmdHis'),
            errorMessage: 'HelpScout API error: 503 Service Unavailable - The server is temporarily unable to handle the request.',
            emailValid: false,
        );
    }

    /**
     * @throws ExternalServiceUnavailableException
     */
    private function sendContactFormProcessed(): void
    {
        $this->chat->sendContactFormProcessed(
            conversationId: IntId::from(123456789),
            customerName: 'Jane Doe',
            customerEmail: 'jane.doe@example.com',
        );
    }

    /**
     * @throws ExternalServiceUnavailableException
     */
    private function sendPricingUpdated(): void
    {
        /** @var int $productId */
        $productId = \config('shopwired.test_product.product_id');
        /** @var string $sku */
        $sku = \config('shopwired.test_product.sku');

        $this->chat->sendPriceUpdateAlert(new PriceUpdateAlertDataDTO(
            productId: IntId::from($productId),
            priceChanges: [
                new SkuPriceChange(
                    sku: Sku::fromTrusted($sku),
                    previousPrices: new ProductRetailPricing(Money::inclusive(10.00)),
                    newPrices: new ProductRetailPricing(Money::inclusive(10.00), Money::inclusive(7.99)),
                ),
            ],
            productTitle: 'Test Product',
            productUrl: 'https://www.alzproducts.co.uk/test-product',
        ));
    }

    /**
     * @throws ExternalServiceUnavailableException
     */
    private function sendVariantSkusGenerated(): void
    {
        /** @var int $productId */
        $productId = \config('shopwired.test_product.product_id');
        /** @var string $sku */
        $sku = \config('shopwired.test_product.sku');

        $this->chat->sendVariantSkusGenerated(new VariantSkuNotificationDataDTO(
            productId: $productId,
            productTitle: 'Test Product',
            created: 3,
            skipped: 1,
            failed: 0,
            createdVariants: [
                "{$sku}-001 - Variant A",
                "{$sku}-002 - Variant B",
                "{$sku}-003 - Variant C",
            ],
        ));
    }

    private function buildBasicNotification(string $message, string $channel): Notification
    {
        return new class ($message, $channel) extends Notification {
            public function __construct(
                private readonly string $message,
                private readonly string $channel,
            ) {}

            /**
             * @return list<string>
             */
            public function via(object $_notifiable): array
            {
                return ['slack'];
            }

            public function toSlack(object $_notifiable): SlackMessage
            {
                return (new SlackMessage())
                    ->to($this->channel)
                    ->text($this->message)
                    ->headerBlock('🧪 Test Notification')
                    ->sectionBlock(static function (SectionBlock $block): void {
                        $block->text('*Source:* alz-core Laravel backend');
                    })
                    ->sectionBlock(function (SectionBlock $block): void {
                        $block->text("*Message:* {$this->message}");
                    })
                    ->dividerBlock()
                    ->contextBlock(static function (ContextBlock $block): void {
                        $block->text('Slack notifications are working correctly.');
                    });
            }
        };
    }
}
