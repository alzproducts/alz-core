<?php

declare(strict_types=1);

namespace App\Infrastructure\Access;

use App\Application\Contracts\Access\ApiKeyCipherInterface;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\CorruptApiKeyException;
use App\Domain\Exceptions\Api\KeyEncryptionFailedException;
use App\Domain\Exceptions\InvalidConfigurationException;
use Illuminate\Support\Facades\Log;
use Random\RandomException;

/**
 * AES-256-GCM cipher for API key storage.
 *
 * Envelope format: `{iv}:{authTag}:{ciphertext}` (all hex-encoded, colon-delimited).
 * Cross-language compatible with Node.js crypto.createCipheriv('aes-256-gcm', key, iv).
 *
 * @phpstan-type CiphertextParts array{0: string, 1: string, 2: string}
 */
final readonly class OpensslApiKeyCipher implements ApiKeyCipherInterface
{
    private const string ALGORITHM = 'aes-256-gcm';

    private const int KEY_HEX_LENGTH = 64;

    private const int IV_LENGTH_BYTES = 12;

    private const int TAG_LENGTH_BYTES = 16;

    private readonly string $keyBinary;

    /**
     * @param string $keyHex 64-character hex string (32 bytes = AES-256 key)
     *
     * @throws InvalidConfigurationException When the key is not 64 hex characters
     */
    public function __construct(string $keyHex)
    {
        if (\mb_strlen($keyHex) !== self::KEY_HEX_LENGTH || !\ctype_xdigit($keyHex)) {
            throw new InvalidConfigurationException(
                'API_KEY_ENCRYPTION_SECRET',
                'API_KEY_ENCRYPTION_SECRET must be a 64-character hex string (32 bytes)',
            );
        }

        $this->keyBinary = (string) \hex2bin($keyHex);
    }

    /**
     * @throws KeyEncryptionFailedException When IV generation or openssl encryption fails
     */
    public function encrypt(ApiKeyToken $token): string
    {
        $iv = $this->generateIv();
        [$ciphertext, $tag] = $this->runOpensslEncrypt($token, $iv);

        return \bin2hex($iv) . ':' . \bin2hex($tag) . ':' . \bin2hex($ciphertext);
    }

    /**
     * Runs `openssl_encrypt` and returns [ciphertext, authTag]. The auth tag is
     * written by openssl into a local variable (its by-ref behaviour is an
     * implementation detail of the built-in, not our API).
     *
     * @return array{string, string}
     *
     * @throws KeyEncryptionFailedException
     */
    private function runOpensslEncrypt(ApiKeyToken $token, string $iv): array
    {
        $tag = '';
        $ciphertext = \openssl_encrypt(
            data: $token->value,
            cipher_algo: self::ALGORITHM,
            passphrase: $this->keyBinary,
            options: \OPENSSL_RAW_DATA,
            iv: $iv,
            tag: $tag,
            tag_length: self::TAG_LENGTH_BYTES,
        );

        if ($ciphertext === false) {
            Log::error('API key encryption failed: openssl_encrypt returned false');
            throw new KeyEncryptionFailedException();
        }

        return [$ciphertext, $tag];
    }

    /**
     * @throws KeyEncryptionFailedException
     */
    private function generateIv(): string
    {
        try {
            return \random_bytes(self::IV_LENGTH_BYTES);
        } catch (RandomException $e) {
            Log::error('API key encryption failed: could not generate secure random bytes', ['error' => $e->getMessage()]);
            throw new KeyEncryptionFailedException($e);
        }
    }

    /**
     * @throws CorruptApiKeyException When the ciphertext is malformed or decryption fails
     */
    public function decrypt(string $ciphertext): ApiKeyToken
    {
        [$iv, $tag, $data] = $this->parseCiphertextEnvelope($ciphertext);

        $plaintext = \openssl_decrypt(
            data: $data,
            cipher_algo: self::ALGORITHM,
            passphrase: $this->keyBinary,
            options: \OPENSSL_RAW_DATA,
            iv: $iv,
            tag: $tag,
        );

        if ($plaintext === false) {
            Log::error('API key decryption failed: openssl_decrypt returned false');
            throw new CorruptApiKeyException();
        }

        return new ApiKeyToken($plaintext);
    }

    /**
     * Parse and hex-decode the `{iv}:{authTag}:{ciphertext}` envelope.
     *
     * @return array{string, string, string}
     *
     * @throws CorruptApiKeyException
     */
    private function parseCiphertextEnvelope(string $ciphertext): array
    {
        $parts = \explode(':', $ciphertext, 3);

        if (\count($parts) !== 3) {
            Log::error('API key decryption failed: malformed ciphertext envelope');
            throw new CorruptApiKeyException();
        }

        /** @var CiphertextParts $parts */
        [$ivHex, $tagHex, $dataHex] = $parts;

        return self::hexDecode($ivHex, $tagHex, $dataHex);
    }

    /**
     * Validate and decode three hex-encoded fields.
     *
     * @return array{string, string, string}
     *
     * @throws CorruptApiKeyException
     */
    private static function hexDecode(string $ivHex, string $tagHex, string $dataHex): array
    {
        if (!\ctype_xdigit($ivHex) || !\ctype_xdigit($tagHex) || !\ctype_xdigit($dataHex)) {
            Log::error('API key decryption failed: hex decode error');
            throw new CorruptApiKeyException();
        }

        return [
            (string) \hex2bin($ivHex),
            (string) \hex2bin($tagHex),
            (string) \hex2bin($dataHex),
        ];
    }
}
