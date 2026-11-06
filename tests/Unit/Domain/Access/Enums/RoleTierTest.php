<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Access\Enums;

use App\Domain\Access\Enums\Role;
use App\Domain\Access\Enums\RoleTier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(RoleTier::class)]
#[CoversClass(Role::class)]
final class RoleTierTest extends TestCase
{
    #[Test]
    public function backing_values_match_jwt_and_route_strings(): void
    {
        $this->assertSame('guest', Role::Guest->value);
        $this->assertSame('standard', Role::Standard->value);
        $this->assertSame('manager', Role::Manager->value);
        $this->assertSame('admin', Role::Admin->value);
        $this->assertSame('staff', RoleTier::Staff->value);
        $this->assertSame('admin_area', RoleTier::AdminArea->value);
    }

    #[Test]
    #[DataProvider('membershipProvider')]
    public function includes_matches_the_tier_membership_table(RoleTier $tier, ?string $roleName, bool $expected): void
    {
        $this->assertSame($expected, $tier->includes($roleName));
    }

    #[Test]
    #[DataProvider('membershipProvider')]
    public function belongs_to_agrees_with_includes(RoleTier $tier, ?string $roleName, bool $expected): void
    {
        $role = Role::tryFrom($roleName ?? '');

        if ($role === null) {
            $this->assertFalse($tier->includes($roleName));

            return;
        }

        $this->assertSame($expected, $role->belongsTo($tier));
    }

    /**
     * @return array<string, array{RoleTier, string|null, bool}>
     */
    public static function membershipProvider(): array
    {
        return [
            'staff: guest' => [RoleTier::Staff, 'guest', false],
            'staff: standard' => [RoleTier::Staff, 'standard', true],
            'staff: manager' => [RoleTier::Staff, 'manager', true],
            'staff: admin' => [RoleTier::Staff, 'admin', true],
            'staff: null' => [RoleTier::Staff, null, false],
            'staff: empty' => [RoleTier::Staff, '', false],
            'staff: tier name staff' => [RoleTier::Staff, 'staff', false],
            'staff: wrong case' => [RoleTier::Staff, 'Admin', false],
            'admin_area: guest' => [RoleTier::AdminArea, 'guest', false],
            'admin_area: standard' => [RoleTier::AdminArea, 'standard', false],
            'admin_area: manager' => [RoleTier::AdminArea, 'manager', true],
            'admin_area: admin' => [RoleTier::AdminArea, 'admin', true],
            'admin_area: null' => [RoleTier::AdminArea, null, false],
            'admin_area: empty' => [RoleTier::AdminArea, '', false],
            'admin_area: tier name staff' => [RoleTier::AdminArea, 'staff', false],
            'admin_area: tier name admin_area' => [RoleTier::AdminArea, 'admin_area', false],
        ];
    }
}
