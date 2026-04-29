<?php

declare(strict_types=1);

namespace App\Domain\Access\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * Typed wrapper for a plaintext third-party API key.
 *
 * Always holds the decrypted value — encryption/decryption is an Infrastructure concern.
 * Never log or include in exception messages; context() intentionally omits the value.
 */
final readonly class ApiKeyToken
{
    public function __construct(
        public string $value,
    ) {
        Assert::notEmpty($value, 'API key token must not be empty');
    }

    public function masked(): string
    {
        if (\mb_strlen($this->value) <= 8) {
            return \str_repeat('*', \mb_strlen($this->value));
        }

        return \mb_substr($this->value, 0, 4) . '...' . \mb_substr($this->value, -4);
    }
}
