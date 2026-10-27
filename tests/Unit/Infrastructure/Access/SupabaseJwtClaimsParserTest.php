<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Access;

use App\Domain\Access\Exceptions\InvalidAccessTokenException;
use App\Infrastructure\Access\SupabaseJwtClaimsParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(SupabaseJwtClaimsParser::class)]
final class SupabaseJwtClaimsParserTest extends TestCase
{
    private const string USER_ID = 'd9dd22a9-c3ab-413b-8a93-25b462231a98';

    #[Test]
    public function it_parses_identity_and_app_metadata_claims(): void
    {
        $claims = SupabaseJwtClaimsParser::parse(self::payload([
            'aal' => 'aal2',
            'app_metadata' => (object) [
                'is_approved' => true,
                'role_name' => 'admin',
                'departments_summary' => ['Sales', 'Warehouse'],
            ],
        ]));

        $this->assertSame(self::USER_ID, $claims->userId);
        $this->assertSame('test@example.com', $claims->email);
        $this->assertSame('aal2', $claims->aal);
        $this->assertTrue($claims->isApproved);
        $this->assertSame('admin', $claims->roleName);
        $this->assertSame(['Sales', 'Warehouse'], $claims->departments);
    }

    #[Test]
    public function it_defaults_absent_optional_claims(): void
    {
        $claims = SupabaseJwtClaimsParser::parse(self::payload());

        $this->assertSame('aal1', $claims->aal);
        $this->assertFalse($claims->isApproved);
        $this->assertNull($claims->roleName);
        $this->assertNull($claims->departments);
    }

    #[Test]
    public function it_splits_a_comma_separated_departments_summary(): void
    {
        $claims = SupabaseJwtClaimsParser::parse(self::payload([
            'app_metadata' => (object) ['departments_summary' => 'Sales,Warehouse'],
        ]));

        $this->assertSame(['Sales', 'Warehouse'], $claims->departments);
    }

    #[Test]
    public function it_only_approves_a_literal_true(): void
    {
        $claims = SupabaseJwtClaimsParser::parse(self::payload([
            'app_metadata' => (object) ['is_approved' => 'true'],
        ]));

        $this->assertFalse($claims->isApproved);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<string>, string, string}>
     */
    public static function invalidPayloads(): iterable
    {
        yield 'missing sub' => [[], ['sub'], 'missing_claim', 'sub'];
        yield 'missing email' => [[], ['email'], 'missing_claim', 'email'];
        yield 'sub wrong type' => [['sub' => 123], [], 'invalid_claim_type', 'sub'];
        yield 'email wrong type' => [['email' => ['a@b.c']], [], 'invalid_claim_type', 'email'];
        yield 'empty sub' => [['sub' => ''], [], 'empty_claim', 'sub'];
        yield 'empty email' => [['email' => ''], [], 'empty_claim', 'email'];
        yield 'non-UUID sub' => [['sub' => 'user-123'], [], 'invalid_claim_value', 'sub'];
        yield 'invalid email' => [['email' => 'not-an-email'], [], 'invalid_claim_value', 'email'];
        yield 'aal wrong type' => [['aal' => 2], [], 'invalid_claim_type', 'aal'];
        yield 'app_metadata wrong type' => [['app_metadata' => 'admin'], [], 'invalid_claim_type', 'app_metadata'];
        yield 'role_name wrong type' => [
            ['app_metadata' => (object) ['role_name' => 1]],
            [],
            'invalid_claim_type',
            'app_metadata.role_name',
        ];
        yield 'departments wrong type' => [
            ['app_metadata' => (object) ['departments_summary' => 5]],
            [],
            'invalid_claim_type',
            'app_metadata.departments_summary',
        ];
        yield 'departments non-string element' => [
            ['app_metadata' => (object) ['departments_summary' => ['Sales', 7]]],
            [],
            'invalid_claim_type',
            'app_metadata.departments_summary',
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @param list<string> $remove
     */
    #[Test]
    #[DataProvider('invalidPayloads')]
    public function it_rejects_invalid_claims(array $overrides, array $remove, string $reason, string $claim): void
    {
        try {
            SupabaseJwtClaimsParser::parse(self::payload($overrides, $remove));
            $this->fail('Expected InvalidAccessTokenException');
        } catch (InvalidAccessTokenException $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertSame($claim, $e->claim);
        }
    }

    /**
     * @param array<string, mixed> $overrides
     * @param list<string> $remove
     */
    private static function payload(array $overrides = [], array $remove = []): stdClass
    {
        $payload = \array_merge(['sub' => self::USER_ID, 'email' => 'test@example.com'], $overrides);
        foreach ($remove as $claim) {
            unset($payload[$claim]);
        }

        return (object) $payload;
    }
}
