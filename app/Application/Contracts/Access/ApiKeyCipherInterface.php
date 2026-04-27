<?php

declare(strict_types=1);

namespace App\Application\Contracts\Access;

use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\CorruptApiKeyException;
use App\Domain\Exceptions\Api\KeyEncryptionFailedException;

interface ApiKeyCipherInterface
{
    /**
     * Encrypt a plaintext API key token.
     *
     * Returns a ciphertext string in `iv:authTag:ciphertext` format (hex-joined, AES-256-GCM).
     *
     * @throws KeyEncryptionFailedException When IV generation or openssl encryption fails
     */
    public function encrypt(ApiKeyToken $token): string;

    /**
     * Decrypt a ciphertext string back to a plaintext API key token.
     *
     * @throws CorruptApiKeyException When the ciphertext is malformed or decryption fails
     */
    public function decrypt(string $ciphertext): ApiKeyToken;
}
