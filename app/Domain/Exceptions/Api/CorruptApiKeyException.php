<?php

declare(strict_types=1);

namespace App\Domain\Exceptions\Api;

use Throwable;

/**
 * Thrown when a stored API-key ciphertext cannot be decrypted.
 *
 * Causes: malformed envelope, GCM tag mismatch, or the encryption key has rotated
 * out from under existing rows. Recovery for the user is the same as for a missing
 * key — re-paste in Settings — so the mapper renders this as HTTP 412 to share the
 * frontend recovery path with {@see MissingApiKeyException}.
 *
 * Distinct from {@see KeyEncryptionFailedException} (encrypt-side fault).
 */
final class CorruptApiKeyException extends PermanentApiFailure
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('API key store', 'Stored API key is corrupt or unreadable', $previous);
    }
}
