<?php

declare(strict_types=1);

namespace App\Domain\Exceptions\Api;

use Throwable;

/**
 * Thrown when local API-key encryption fails (openssl crash, bad cipher state).
 *
 * System-level fault — not retryable, not the user's problem to fix.
 * Distinct from {@see CorruptApiKeyException} (decrypt failure on existing data).
 */
final class KeyEncryptionFailedException extends PermanentApiFailure
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('key encryption', 'API key encryption failed', $previous);
    }
}
