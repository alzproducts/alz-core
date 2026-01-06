<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Access\ValueObjects;

use App\Domain\Access\ValueObjects\AuthenticatedUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webmozart\Assert\InvalidArgumentException;

#[CoversClass(AuthenticatedUser::class)]
final class AuthenticatedUserTest extends TestCase
{
    private const string VALID_UUID = 'd9dd22a9-c3ab-413b-8a93-25b462231a98';

    private const string VALID_EMAIL = 'test@example.com';

    // ─────────────────────────────────────────────────────────────────────────
    // Construction Tests
    // ─────────────────────────────────────────────────────────────────────────

    public function testConstructsWithValidUuidAndEmail(): void
    {
        $user = new AuthenticatedUser(
            id: self::VALID_UUID,
            email: self::VALID_EMAIL,
            isApproved: true,
            roleName: 'admin',
            departments: ['Sales', 'Marketing'],
        );

        $this->assertSame(self::VALID_UUID, $user->id);
        $this->assertSame(self::VALID_EMAIL, $user->email);
        $this->assertTrue($user->isApproved);
        $this->assertSame('admin', $user->roleName);
        $this->assertSame(['Sales', 'Marketing'], $user->departments);
    }

    public function testConstructsWithMinimalRequiredParameters(): void
    {
        $user = new AuthenticatedUser(
            id: self::VALID_UUID,
            email: self::VALID_EMAIL,
            isApproved: false,
        );

        $this->assertSame(self::VALID_UUID, $user->id);
        $this->assertSame(self::VALID_EMAIL, $user->email);
        $this->assertFalse($user->isApproved);
        $this->assertNull($user->roleName);
        $this->assertNull($user->departments);
    }

    #[DataProvider('invalidUuidProvider')]
    public function testThrowsExceptionForInvalidUuid(string $invalidUuid): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('User ID must be a valid UUID');

        new AuthenticatedUser(
            id: $invalidUuid,
            email: self::VALID_EMAIL,
            isApproved: true,
        );
    }

    #[DataProvider('invalidEmailProvider')]
    public function testThrowsExceptionForInvalidEmail(string $invalidEmail): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('User email must be valid');

        new AuthenticatedUser(
            id: self::VALID_UUID,
            email: $invalidEmail,
            isApproved: true,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // hasBasicAuthorization() Tests
    // ─────────────────────────────────────────────────────────────────────────

    public function testHasBasicAuthorizationReturnsTrueWhenApproved(): void
    {
        $user = new AuthenticatedUser(
            id: self::VALID_UUID,
            email: self::VALID_EMAIL,
            isApproved: true,
        );

        $this->assertTrue($user->hasBasicAuthorization());
    }

    public function testHasBasicAuthorizationReturnsFalseWhenNotApproved(): void
    {
        $user = new AuthenticatedUser(
            id: self::VALID_UUID,
            email: self::VALID_EMAIL,
            isApproved: false,
        );

        $this->assertFalse($user->hasBasicAuthorization());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // hasRole() Tests
    // ─────────────────────────────────────────────────────────────────────────

    public function testHasRoleReturnsTrueWhenRoleMatches(): void
    {
        $user = new AuthenticatedUser(
            id: self::VALID_UUID,
            email: self::VALID_EMAIL,
            isApproved: true,
            roleName: 'staff',
        );

        $this->assertTrue($user->hasRole('staff'));
    }

    public function testHasRoleReturnsFalseWhenRoleDoesNotMatch(): void
    {
        $user = new AuthenticatedUser(
            id: self::VALID_UUID,
            email: self::VALID_EMAIL,
            isApproved: true,
            roleName: 'staff',
        );

        $this->assertFalse($user->hasRole('admin'));
    }

    public function testHasRoleReturnsFalseWhenRoleNameIsNull(): void
    {
        $user = new AuthenticatedUser(
            id: self::VALID_UUID,
            email: self::VALID_EMAIL,
            isApproved: true,
            roleName: null,
        );

        $this->assertFalse($user->hasRole('admin'));
    }

    public function testHasRoleIsCaseSensitive(): void
    {
        $user = new AuthenticatedUser(
            id: self::VALID_UUID,
            email: self::VALID_EMAIL,
            isApproved: true,
            roleName: 'admin',
        );

        // Exact match works
        $this->assertTrue($user->hasRole('admin'));

        // Case mismatch fails (mutation killer for string comparisons)
        $this->assertFalse($user->hasRole('Admin'));
        $this->assertFalse($user->hasRole('ADMIN'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // isAdmin() Tests
    // ─────────────────────────────────────────────────────────────────────────

    public function testIsAdminReturnsTrueWhenRoleIsAdmin(): void
    {
        $user = new AuthenticatedUser(
            id: self::VALID_UUID,
            email: self::VALID_EMAIL,
            isApproved: true,
            roleName: 'admin',
        );

        $this->assertTrue($user->isAdmin());
    }

    public function testIsAdminReturnsFalseWhenRoleIsNotAdmin(): void
    {
        $user = new AuthenticatedUser(
            id: self::VALID_UUID,
            email: self::VALID_EMAIL,
            isApproved: true,
            roleName: 'staff',
        );

        $this->assertFalse($user->isAdmin());
    }

    public function testIsAdminReturnsFalseWhenRoleNameIsNull(): void
    {
        $user = new AuthenticatedUser(
            id: self::VALID_UUID,
            email: self::VALID_EMAIL,
            isApproved: true,
            roleName: null,
        );

        $this->assertFalse($user->isAdmin());
    }

    public function testIsAdminIsCaseSensitive(): void
    {
        // 'Admin' (capital A) should NOT be considered admin
        $user = new AuthenticatedUser(
            id: self::VALID_UUID,
            email: self::VALID_EMAIL,
            isApproved: true,
            roleName: 'Admin',
        );

        $this->assertFalse($user->isAdmin());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Data Providers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{string}>
     */
    public static function invalidUuidProvider(): array
    {
        return [
            'empty string' => [''],
            'plain string' => ['not-a-uuid'],
            'too short' => ['12345678'],
            'malformed uuid' => ['12345678-1234-5678-1234-12345678901Z'],
            'uuid without hyphens' => ['d9dd22a9c3ab413b8a9325b462231a98'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidEmailProvider(): array
    {
        return [
            'empty string' => [''],
            'missing at sign' => ['testexample.com'],
            'missing domain' => ['test@'],
            'only at sign' => ['@'],
            'spaces in email' => ['test @example.com'],
        ];
    }
}
