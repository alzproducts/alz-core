<?php

declare(strict_types=1);

namespace App\Domain\Access\Enums;

/**
 * A user's assigned role, as carried in the JWT `role_name` claim.
 */
enum Role: string
{
    case Guest = 'guest';
    case Standard = 'standard';
    case Manager = 'manager';
    case Admin = 'admin';

    public function belongsTo(RoleTier $tier): bool
    {
        return match ($tier) {
            RoleTier::Staff => match ($this) {
                self::Standard, self::Manager, self::Admin => true,
                self::Guest => false,
            },
            RoleTier::AdminArea => match ($this) {
                self::Manager, self::Admin => true,
                self::Standard, self::Guest => false,
            },
        };
    }
}
