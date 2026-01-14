<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Order\ValueObjects;

use App\Domain\Catalog\Order\ValueObjects\OrderAnalyticsHash;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * OrderAnalyticsHash Value Object Tests.
 *
 * Tests hash generation and validation rules. Critical for frontend/backend
 * deduplication - hash algorithm MUST match frontend implementation.
 */
#[CoversClass(OrderAnalyticsHash::class)]
final class OrderAnalyticsHashTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Factory Method: fromReference
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_generates_hash_from_reference_and_salt(): void
    {
        $hash = OrderAnalyticsHash::fromReference(12345, 'test-salt');

        // SHA-256 always produces exactly 64 hex characters
        $this->assertSame(64, \mb_strlen($hash->value));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash->value);
    }

    #[Test]
    public function it_accepts_reference_of_one(): void
    {
        // Edge case: reference=1 must be valid (boundary test)
        $hash = OrderAnalyticsHash::fromReference(1, 'test-salt');
        $expectedHash = \hash('sha256', '1' . 'test-salt');

        $this->assertSame($expectedHash, $hash->value);
    }

    #[Test]
    public function it_produces_deterministic_hash_for_same_inputs(): void
    {
        $hash1 = OrderAnalyticsHash::fromReference(12345, 'test-salt');
        $hash2 = OrderAnalyticsHash::fromReference(12345, 'test-salt');

        $this->assertSame($hash1->value, $hash2->value);
    }

    #[Test]
    public function it_produces_different_hashes_for_different_references(): void
    {
        $hash1 = OrderAnalyticsHash::fromReference(12345, 'test-salt');
        $hash2 = OrderAnalyticsHash::fromReference(12346, 'test-salt');

        $this->assertNotSame($hash1->value, $hash2->value);
    }

    #[Test]
    public function it_produces_different_hashes_for_different_salts(): void
    {
        $hash1 = OrderAnalyticsHash::fromReference(12345, 'salt-a');
        $hash2 = OrderAnalyticsHash::fromReference(12345, 'salt-b');

        $this->assertNotSame($hash1->value, $hash2->value);
    }

    #[Test]
    public function it_matches_frontend_sha256_algorithm(): void
    {
        // Algorithm: SHA-256(reference + salt)
        // This test ensures we match the frontend implementation
        $reference = 99999;
        $salt = 'analytics-secret';
        $expectedHash = \hash('sha256', $reference . $salt);

        $hash = OrderAnalyticsHash::fromReference($reference, $salt);

        $this->assertSame($expectedHash, $hash->value);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation: Reference
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_rejects_zero_reference(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Order reference must be positive');

        OrderAnalyticsHash::fromReference(0, 'salt');
    }

    #[Test]
    public function it_rejects_negative_reference(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Order reference must be positive');

        OrderAnalyticsHash::fromReference(-1, 'salt');
    }

    /*
    |--------------------------------------------------------------------------
    | Validation: Salt
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_rejects_empty_salt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Analytics salt cannot be empty');

        OrderAnalyticsHash::fromReference(12345, '');
    }

    /*
    |--------------------------------------------------------------------------
    | String Conversion
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_converts_to_string_via_magic_method(): void
    {
        $hash = OrderAnalyticsHash::fromReference(12345, 'test-salt');

        $this->assertSame($hash->value, (string) $hash);
    }

    /*
    |--------------------------------------------------------------------------
    | Constant Verification
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function hash_length_constant_is_64(): void
    {
        // Verify the HASH_LENGTH constant equals SHA-256 output length.
        // This test exists to catch mutations that change the constant value.
        $reflection = new ReflectionClass(OrderAnalyticsHash::class);
        $constant = $reflection->getConstant('HASH_LENGTH');

        $this->assertSame(64, $constant);
    }

    /*
    |--------------------------------------------------------------------------
    | Direct Construction (for hydration from Mixpanel export)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_accepts_valid_hash_directly(): void
    {
        $validHash = \hash('sha256', 'test-input');

        $hash = new OrderAnalyticsHash($validHash);

        $this->assertSame($validHash, $hash->value);
    }

    #[Test]
    public function it_accepts_hash_with_exactly_64_characters(): void
    {
        // Valid SHA-256 hash is exactly 64 hex characters
        $validHash = \str_repeat('a', 64);

        $hash = new OrderAnalyticsHash($validHash);

        $this->assertSame($validHash, $hash->value);
        $this->assertSame(64, \mb_strlen($hash->value));
    }

    #[Test]
    public function it_rejects_hash_with_wrong_length(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Order analytics hash must be 64 characters');

        new OrderAnalyticsHash('abc123'); // Too short
    }

    #[Test]
    public function it_rejects_hash_with_63_characters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Order analytics hash must be 64 characters');

        // Exactly 63 characters - one short of valid SHA-256
        new OrderAnalyticsHash(\str_repeat('a', 63));
    }

    #[Test]
    public function it_rejects_hash_with_65_characters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Order analytics hash must be 64 characters');

        // Exactly 65 characters - one more than valid SHA-256
        new OrderAnalyticsHash(\str_repeat('a', 65));
    }

    #[Test]
    public function it_rejects_hash_with_uppercase_characters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Order analytics hash must be lowercase hex');

        // 64 characters but uppercase
        new OrderAnalyticsHash('ABC' . \str_repeat('0', 61));
    }

    #[Test]
    public function it_rejects_hash_with_non_hex_characters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Order analytics hash must be lowercase hex');

        // 64 characters but contains invalid character 'g'
        new OrderAnalyticsHash(\str_repeat('g', 64));
    }
}
