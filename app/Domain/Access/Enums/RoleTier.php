<?php

declare(strict_types=1);

namespace App\Domain\Access\Enums;

/**
 * A named set of Roles allowed through a group of API routes (ADR-0014).
 *
 * A tier is not a role: no JWT carries `role_name = 'staff'`.
 */
enum RoleTier: string
{
    case Staff = 'staff';
    case AdminArea = 'admin_area';

    /**
     * Fails closed: a null, empty or unrecognised role name is never included.
     */
    public function includes(?string $roleName): bool
    {
        return Role::tryFrom($roleName ?? '')?->belongsTo($this) ?? false;
    }
}
