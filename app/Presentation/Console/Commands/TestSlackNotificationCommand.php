<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

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
        {message? : Custom message to send}';

    protected $description = 'Send a test notification to Slack';

    public function handle(): int
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
}
