<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout;

use App\Domain\Exceptions\InvalidConfigurationException;
use InvalidArgumentException;

/**
 * Immutable configuration for HelpScout API client.
 *
 * Validation happens at construction time (fail-fast), ensuring the client
 * always receives valid configuration.
 *
 * @template-pattern API Client Config Value Object
 */
final readonly class HelpScoutConfig
{
    /**
     * HelpScout API base URL (v2).
     */
    public const string BASE_URL = 'https://api.helpscout.net/v2';

    /**
     * Default timeout in seconds for API requests.
     */
    private const int DEFAULT_TIMEOUT_SECONDS = 30;

    /**
     * Maximum allowed timeout in seconds.
     */
    private const int MAX_TIMEOUT_SECONDS = 120;

    /**
     * Default retry attempts for transient failures.
     */
    private const int DEFAULT_RETRY_ATTEMPTS = 3;

    /**
     * Maximum retry attempts allowed.
     */
    private const int MAX_RETRY_ATTEMPTS = 10;

    /**
     * @param array<string, int> $mailboxes Mailbox IDs ['support' => id, 'purchase_orders' => id, ...]
     * @param int $timeoutSeconds HTTP timeout in seconds (1-120)
     * @param int $retryAttempts Number of retry attempts for transient failures (1-10)
     *
     * @throws InvalidConfigurationException When mailboxes are not configured
     * @throws InvalidArgumentException When parameters are invalid
     */
    public function __construct(
        public array $mailboxes,
        public int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        public int $retryAttempts = self::DEFAULT_RETRY_ATTEMPTS,
    ) {
        if ($mailboxes === []) {
            throw new InvalidConfigurationException('helpscout.mailboxes', 'At least one HelpScout mailbox must be configured');
        }

        foreach ($mailboxes as $name => $id) {
            if ($name === '') {
                throw new InvalidArgumentException('Mailbox name must be a non-empty string');
            }

            if ($id <= 0) {
                throw new InvalidArgumentException(
                    \sprintf("Mailbox '%s' ID must be a positive integer, got %d", $name, $id),
                );
            }
        }

        if (($timeoutSeconds < 1) || ($timeoutSeconds > self::MAX_TIMEOUT_SECONDS)) {
            throw new InvalidArgumentException(
                \sprintf('Timeout must be between 1-%d seconds, got %d', self::MAX_TIMEOUT_SECONDS, $timeoutSeconds),
            );
        }

        if (($retryAttempts < 1) || ($retryAttempts > self::MAX_RETRY_ATTEMPTS)) {
            throw new InvalidArgumentException(
                \sprintf('Retry attempts must be between 1-%d, got %d', self::MAX_RETRY_ATTEMPTS, $retryAttempts),
            );
        }
    }

    /**
     * Get a specific mailbox ID by name.
     *
     * @throws InvalidArgumentException When mailbox name is not configured
     */
    public function getMailboxId(string $name): int
    {
        if (!isset($this->mailboxes[$name])) {
            throw new InvalidArgumentException(
                \sprintf(
                    "Mailbox '%s' not configured. Available: %s",
                    $name,
                    \implode(', ', \array_keys($this->mailboxes)),
                ),
            );
        }

        return $this->mailboxes[$name];
    }
}
