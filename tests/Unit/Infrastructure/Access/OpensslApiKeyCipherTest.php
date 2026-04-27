<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Access;

use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\CorruptApiKeyException;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Infrastructure\Access\OpensslApiKeyCipher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(OpensslApiKeyCipher::class)]
final class OpensslApiKeyCipherTest extends TestCase
{
    private const string TEST_KEY_HEX = 'deadbeef1234567890abcdef1234567890abcdef1234567890abcdef12345678';

    private OpensslApiKeyCipher $cipher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cipher = new OpensslApiKeyCipher(self::TEST_KEY_HEX);
    }

    #[Test]
    public function round_trip_encrypt_then_decrypt_returns_original_token(): void
    {
        $original = new ApiKeyToken('pk_test_clickup_api_key_12345');

        $ciphertext = $this->cipher->encrypt($original);
        $decrypted = $this->cipher->decrypt($ciphertext);

        $this->assertSame($original->value, $decrypted->value);
    }

    #[Test]
    public function each_encrypt_produces_a_unique_ciphertext_due_to_random_iv(): void
    {
        $token = new ApiKeyToken('same_api_key');

        $first = $this->cipher->encrypt($token);
        $second = $this->cipher->encrypt($token);

        $this->assertNotSame($first, $second);
    }

    #[Test]
    public function ciphertext_is_in_iv_authTag_ciphertext_format(): void
    {
        $ciphertext = $this->cipher->encrypt(new ApiKeyToken('test_key'));

        $parts = \explode(':', $ciphertext);
        $this->assertCount(3, $parts, 'Ciphertext must have 3 colon-separated parts');
        $this->assertSame(24, \mb_strlen($parts[0]), 'IV must be 12 bytes (24 hex chars)');
        $this->assertSame(32, \mb_strlen($parts[1]), 'Auth tag must be 16 bytes (32 hex chars)');
        $this->assertGreaterThan(0, \mb_strlen($parts[2]), 'Ciphertext part must be non-empty');
    }

    #[Test]
    #[DataProvider('malformedCiphertextProvider')]
    public function decrypt_throws_for_malformed_ciphertext(string $ciphertext): void
    {
        $this->expectException(CorruptApiKeyException::class);

        $this->cipher->decrypt($ciphertext);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedCiphertextProvider(): array
    {
        return [
            'empty string' => [''],
            'no colons' => ['deadbeef0123456789abcdef'],
            'only one colon' => ['aabb:ccdd'],
            'non-hex iv' => ['gggggggggggggggggggggggg:aabbccddeeff00112233445566778899:cafebabe'],
        ];
    }

    #[Test]
    public function decrypt_throws_when_key_does_not_match(): void
    {
        $token = new ApiKeyToken('secret_key');
        $ciphertext = $this->cipher->encrypt($token);

        $wrongKeyCipher = new OpensslApiKeyCipher(
            'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff',
        );

        $this->expectException(CorruptApiKeyException::class);
        $wrongKeyCipher->decrypt($ciphertext);
    }

    #[Test]
    #[DataProvider('invalidKeyHexProvider')]
    public function constructor_rejects_invalid_key_hex(string $keyHex): void
    {
        $this->expectException(InvalidConfigurationException::class);

        new OpensslApiKeyCipher($keyHex);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidKeyHexProvider(): array
    {
        return [
            'empty string' => [''],
            'too short' => ['deadbeef'],
            'too long (66 chars)' => ['deadbeef1234567890abcdef1234567890abcdef1234567890abcdef1234567890'],
            'right length but non-hex' => ['ZZZZbeef1234567890abcdef1234567890abcdef1234567890abcdef12345678'],
        ];
    }
}
