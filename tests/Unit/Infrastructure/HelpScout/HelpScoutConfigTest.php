<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\HelpScout;

use App\Domain\Exceptions\InvalidConfigurationException;
use App\Infrastructure\HelpScout\HelpScoutConfig;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(HelpScoutConfig::class)]
final class HelpScoutConfigTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Construction Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_config_with_valid_mailboxes(): void
    {
        $config = new HelpScoutConfig(
            mailboxes: ['support' => 12345, 'sales' => 67890],
        );

        $this->assertSame(['support' => 12345, 'sales' => 67890], $config->mailboxes);
    }

    #[Test]
    public function it_uses_default_timeout_when_not_specified(): void
    {
        $config = new HelpScoutConfig(
            mailboxes: ['support' => 12345],
        );

        $this->assertSame(30, $config->timeoutSeconds);
    }

    #[Test]
    public function it_uses_default_retry_attempts_when_not_specified(): void
    {
        $config = new HelpScoutConfig(
            mailboxes: ['support' => 12345],
        );

        $this->assertSame(3, $config->retryAttempts);
    }

    #[Test]
    public function it_accepts_custom_timeout(): void
    {
        $config = new HelpScoutConfig(
            mailboxes: ['support' => 12345],
            timeoutSeconds: 60,
        );

        $this->assertSame(60, $config->timeoutSeconds);
    }

    #[Test]
    public function it_accepts_custom_retry_attempts(): void
    {
        $config = new HelpScoutConfig(
            mailboxes: ['support' => 12345],
            retryAttempts: 5,
        );

        $this->assertSame(5, $config->retryAttempts);
    }

    /*
    |--------------------------------------------------------------------------
    | Mailbox Validation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_runtime_exception_when_mailboxes_empty(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('At least one HelpScout mailbox must be configured');

        new HelpScoutConfig(mailboxes: []);
    }

    #[Test]
    public function it_throws_when_mailbox_name_is_empty_string(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Mailbox name must be a non-empty string');

        new HelpScoutConfig(mailboxes: ['' => 12345]);
    }

    #[Test]
    #[DataProvider('invalidMailboxIdProvider')]
    public function it_throws_when_mailbox_id_is_not_positive(int $invalidId): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Mailbox 'support' ID must be a positive integer");

        new HelpScoutConfig(mailboxes: ['support' => $invalidId]);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function invalidMailboxIdProvider(): array
    {
        return [
            'zero' => [0],
            'negative one' => [-1],
            'large negative' => [-9999],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Timeout Validation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_accepts_minimum_timeout_of_one_second(): void
    {
        $config = new HelpScoutConfig(
            mailboxes: ['support' => 12345],
            timeoutSeconds: 1,
        );

        $this->assertSame(1, $config->timeoutSeconds);
    }

    #[Test]
    public function it_accepts_maximum_timeout_of_120_seconds(): void
    {
        $config = new HelpScoutConfig(
            mailboxes: ['support' => 12345],
            timeoutSeconds: 120,
        );

        $this->assertSame(120, $config->timeoutSeconds);
    }

    #[Test]
    public function it_throws_when_timeout_is_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Timeout must be between 1-120 seconds, got 0');

        new HelpScoutConfig(
            mailboxes: ['support' => 12345],
            timeoutSeconds: 0,
        );
    }

    #[Test]
    public function it_throws_when_timeout_exceeds_maximum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Timeout must be between 1-120 seconds, got 121');

        new HelpScoutConfig(
            mailboxes: ['support' => 12345],
            timeoutSeconds: 121,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Retry Attempts Validation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_accepts_minimum_retry_attempts_of_one(): void
    {
        $config = new HelpScoutConfig(
            mailboxes: ['support' => 12345],
            retryAttempts: 1,
        );

        $this->assertSame(1, $config->retryAttempts);
    }

    #[Test]
    public function it_accepts_maximum_retry_attempts_of_ten(): void
    {
        $config = new HelpScoutConfig(
            mailboxes: ['support' => 12345],
            retryAttempts: 10,
        );

        $this->assertSame(10, $config->retryAttempts);
    }

    #[Test]
    public function it_throws_when_retry_attempts_is_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Retry attempts must be between 1-10, got 0');

        new HelpScoutConfig(
            mailboxes: ['support' => 12345],
            retryAttempts: 0,
        );
    }

    #[Test]
    public function it_throws_when_retry_attempts_exceeds_maximum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Retry attempts must be between 1-10, got 11');

        new HelpScoutConfig(
            mailboxes: ['support' => 12345],
            retryAttempts: 11,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | getMailboxId Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function get_mailbox_id_returns_correct_id(): void
    {
        $config = new HelpScoutConfig(
            mailboxes: ['support' => 12345, 'sales' => 67890, 'billing' => 11111],
        );

        $this->assertSame(12345, $config->getMailboxId('support'));
        $this->assertSame(67890, $config->getMailboxId('sales'));
        $this->assertSame(11111, $config->getMailboxId('billing'));
    }

    #[Test]
    public function get_mailbox_id_throws_when_mailbox_not_found(): void
    {
        $config = new HelpScoutConfig(
            mailboxes: ['support' => 12345, 'sales' => 67890],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Mailbox 'unknown' not configured. Available: support, sales");

        $config->getMailboxId('unknown');
    }

    /*
    |--------------------------------------------------------------------------
    | Constant Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_has_correct_base_url_constant(): void
    {
        $this->assertSame('https://api.helpscout.net/v2', HelpScoutConfig::BASE_URL);
    }
}
