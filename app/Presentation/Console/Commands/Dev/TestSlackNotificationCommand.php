<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands\Dev;

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
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Notifications\Slack\ContactFormFailedNotification;
use App\Infrastructure\Notifications\Slack\ContactFormProcessedNotification;
use App\Infrastructure\Notifications\Slack\ProductPricingUpdatedNotification;
use App\Infrastructure\Notifications\Slack\VariantSkusGeneratedNotification;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\BlockKit\Blocks\ContextBlock;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Throwable;

/**
 * Test Slack notification connectivity.
 *
 * Sends a test message to verify that Slack bot credentials are configured
 * correctly and the bot has permission to post to the target channel.
 */
final class TestSlackNotificationCommand extends Command
{
    protected $signature = 'slack:test
        {channel? : The Slack channel to send to (e.g., #dev-notifications)}
        {message? : Custom message to send}
        {--notification= : Test a notification class (contact-failed, contact-processed, variant-skus, pricing-updated)}';

    protected $description = 'Send a test notification to Slack';

    public function handle(): int
    {
        /** @var string|null $notificationType */
        $notificationType = $this->option('notification');

        if ($notificationType !== null) {
            return $this->handleNotification($notificationType);
        }

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
            NotificationFacade::route('slack', $channel)
                ->notify($this->buildNotification($message, $channel));

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

    private function buildNotification(string $message, string $channel): Notification
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

    private function handleNotification(string $type): int
    {
        $notification = match ($type) {
            'contact-failed' => $this->buildContactFailedNotification(),
            'contact-processed' => $this->buildContactProcessedNotification(),
            'variant-skus' => $this->buildVariantSkusNotification(),
            'pricing-updated' => $this->buildPricingUpdatedNotification(),
            default => null,
        };

        if ($notification === null) {
            $this->error("Unknown notification type: {$type}");
            $this->line('  Available: contact-failed, contact-processed, variant-skus, pricing-updated');

            return self::FAILURE;
        }

        // Determine channel based on notification type
        /** @var string|null $channel */
        $channel = $this->argument('channel');

        if ($channel === null) {
            $channel = match ($type) {
                'contact-processed', 'pricing-updated' => \config('services.slack.notifications.verbose_channel'),
                default => \config('services.slack.notifications.channel'),
            };
        }

        if (! \is_string($channel) || $channel === '') {
            $configKey = match ($type) {
                'contact-processed', 'pricing-updated' => 'SLACK_VERBOSE_CHANNEL',
                default => 'SLACK_BOT_USER_DEFAULT_CHANNEL',
            };
            $this->error("No channel specified and {$configKey} is not set.");

            return self::FAILURE;
        }

        $token = \config('services.slack.notifications.bot_user_oauth_token');

        if (! \is_string($token) || $token === '') {
            $this->error('SLACK_BOT_USER_OAUTH_TOKEN is not configured.');

            return self::FAILURE;
        }

        $this->info("Sending {$type} notification to {$channel}...");

        try {
            NotificationFacade::route('slack', $channel)->notify($notification);

            $this->info('✓ Notification sent successfully');
            $this->line("  Type: {$type}");
            $this->line("  Channel: {$channel}");

            return self::SUCCESS;
        } catch (Throwable $e) { // @ignoreException - connectivity test: report failure to user
            $this->error('✗ Failed to send notification');
            $this->line("  Error: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    private function buildContactFailedNotification(): ContactFormFailedNotification
    {
        // Simulate a submission from ~15 minutes ago to test time display
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
                sku: null, // Testing product with ID but no SKU
                title: 'Folding Mobility Scooter - Blue',
                price: '£899.00',
            ),
            submittedAt: $submittedAt,
        );

        return new ContactFormFailedNotification(
            submission: $submission,
            submissionId: 'test-' . \now()->format('YmdHis'),
            errorMessage: 'HelpScout API error: 503 Service Unavailable - The server is temporarily unable to handle the request.',
            emailValid: false, // Test the email validity warning display
        );
    }

    private function buildContactProcessedNotification(): ContactFormProcessedNotification
    {
        return new ContactFormProcessedNotification(
            conversationId: 123456789,
            customerName: 'Jane Doe',
            customerEmail: 'jane.doe@example.com',
        );
    }

    private function buildPricingUpdatedNotification(): ProductPricingUpdatedNotification
    {
        return new ProductPricingUpdatedNotification(
            productId: 5585518,
            priceChanges: [
                new SkuPriceChange(
                    sku: Sku::fromTrusted('WEB-15424'),
                    previousPrices: new ProductRetailPricing(Money::inclusive(24.99)),
                    newPrices: new ProductRetailPricing(Money::inclusive(19.99), Money::inclusive(19.99)),
                ),
                new SkuPriceChange(
                    sku: Sku::fromTrusted('WEB-15424-001'),
                    previousPrices: new ProductRetailPricing(Money::inclusive(29.99), Money::inclusive(24.99)),
                    newPrices: new ProductRetailPricing(Money::inclusive(29.99)),
                ),
                new SkuPriceChange(
                    sku: Sku::fromTrusted('WEB-15424-002'),
                    previousPrices: new ProductRetailPricing(Money::inclusive(34.99)),
                    newPrices: new ProductRetailPricing(Money::inclusive(27.99)),
                ),
                new SkuPriceChange(
                    sku: Sku::fromTrusted('WEB-15424-003'),
                    previousPrices: new ProductRetailPricing(Money::inclusive(39.99)),
                    newPrices: new ProductRetailPricing(Money::inclusive(34.99), Money::inclusive(29.99)),
                ),
            ],
        );
    }

    private function buildVariantSkusNotification(): VariantSkusGeneratedNotification
    {
        return new VariantSkusGeneratedNotification(
            productId: 5585518,
            productTitle: 'Bathroom Sign - Budget & Premium Range',
            created: 8,
            skipped: 4,
            failed: 1,
            createdVariants: [
                'WEB-15424-001 - Budget Self-Adhesive 300mm Blue',
                'WEB-15424-002 - Budget Self-Adhesive 300mm Green',
                'WEB-15424-003 - Budget Self-Adhesive 300mm Red',
                'WEB-15424-004 - Budget Self-Adhesive 300mm Yellow',
                'WEB-15424-005 - Budget Fixings 300mm Blue',
                'WEB-15424-006 - Budget Fixings 300mm Green',
                'WEB-15424-007 - Premium Self-Adhesive 300mm Blue',
                'WEB-15424-008 - Premium Self-Adhesive 300mm Green',
            ],
        );
    }
}
