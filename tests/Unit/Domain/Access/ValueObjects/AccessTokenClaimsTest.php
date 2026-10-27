<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Access\ValueObjects;

use App\Domain\Access\ValueObjects\AccessTokenClaims;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AccessTokenClaims::class)]
final class AccessTokenClaimsTest extends TestCase
{
    private const string USER_ID = 'd9dd22a9-c3ab-413b-8a93-25b462231a98';

    private const string EMAIL = 'test@example.com';

    #[Test]
    public function it_is_mfa_verified_only_for_aal2(): void
    {
        $this->assertTrue(self::claims(aal: 'aal2')->isMfaVerified());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonMfaAssuranceLevels(): iterable
    {
        yield 'aal1' => ['aal1'];
        yield 'uppercase aal2' => ['AAL2'];
        yield 'aal3' => ['aal3'];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('nonMfaAssuranceLevels')]
    public function it_is_not_mfa_verified_for_other_assurance_levels(string $aal): void
    {
        $this->assertFalse(self::claims(aal: $aal)->isMfaVerified());
    }

    #[Test]
    public function it_builds_an_authenticated_user_from_its_claims(): void
    {
        $claims = new AccessTokenClaims(
            userId: self::USER_ID,
            email: self::EMAIL,
            aal: 'aal2',
            isApproved: true,
            roleName: 'admin',
            departments: ['Sales', 'Warehouse'],
        );

        $user = $claims->toAuthenticatedUser();

        $this->assertSame(self::USER_ID, $user->id);
        $this->assertSame(self::EMAIL, $user->email);
        $this->assertTrue($user->isApproved);
        $this->assertSame('admin', $user->roleName);
        $this->assertSame(['Sales', 'Warehouse'], $user->departments);
    }

    #[Test]
    public function it_carries_unapproved_and_absent_optional_claims_to_the_user(): void
    {
        $user = self::claims(aal: 'aal1')->toAuthenticatedUser();

        $this->assertFalse($user->isApproved);
        $this->assertNull($user->roleName);
        $this->assertNull($user->departments);
    }

    #[Test]
    public function it_rejects_a_non_uuid_user_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AccessTokenClaims('not-a-uuid', self::EMAIL, 'aal2', true, null, null);
    }

    #[Test]
    public function it_rejects_an_invalid_email(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AccessTokenClaims(self::USER_ID, 'not-an-email', 'aal2', true, null, null);
    }

    private static function claims(string $aal): AccessTokenClaims
    {
        return new AccessTokenClaims(
            userId: self::USER_ID,
            email: self::EMAIL,
            aal: $aal,
            isApproved: false,
            roleName: null,
            departments: null,
        );
    }
}
