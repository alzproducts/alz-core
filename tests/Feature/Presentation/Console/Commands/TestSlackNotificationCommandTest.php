<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Console\Commands;

use App\Application\Contracts\ChatNotificationInterface;
use App\Presentation\Console\Commands\Dev\TestSlackNotificationCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(TestSlackNotificationCommand::class)]
final class TestSlackNotificationCommandTest extends TestCase
{
    /**
     * Artisan builds every command at start, so `composer install` in production-defaulted CI
     * (no secrets) must not resolve the Slack binding, which throws there (ADR-0015).
     */
    #[Test]
    public function it_builds_in_production_with_a_blank_slack_token(): void
    {
        \config(['services.slack.notifications.bot_user_oauth_token' => null]);
        $this->app->detectEnvironment(static fn(): string => 'production');

        $this->assertInstanceOf(TestSlackNotificationCommand::class, $this->app->make(TestSlackNotificationCommand::class));
    }

    #[Test]
    public function the_notification_mode_refuses_a_blank_token_instead_of_reporting_the_stand_in_as_sent(): void
    {
        \config(['services.slack.notifications.bot_user_oauth_token' => '']);
        $this->mock(ChatNotificationInterface::class)->shouldNotReceive('sendAlert');

        $this->artisan('slack:test', ['--notification' => 'admin-alert'])
            ->expectsOutput('SLACK_BOT_USER_OAUTH_TOKEN is not configured.')
            ->doesntExpectOutput('✓ Notification sent successfully')
            ->assertFailed();
    }
}
