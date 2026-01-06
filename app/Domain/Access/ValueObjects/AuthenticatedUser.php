<?php

declare(strict_types=1);

namespace App\Domain\Access\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * Represents a user who has successfully authenticated via Supabase JWT + MFA.
 *
 * This value object contains identity and authorization claims.
 * It does NOT enforce authorization rules - it simply holds the data for downstream
 * code to make authorization decisions.
 *
 * ============================================================
 * IMPORTANT: APPROVAL IS REQUIRED FOR ALMOST ALL OPERATIONS
 * ============================================================
 *
 * The `isApproved` property indicates whether an admin has approved this user.
 * Almost all API endpoints require approval - unapproved users should only be
 * able to access specific "pending approval" functionality.
 *
 * Authorization enforcement happens via:
 * - EnsureUserApprovedMiddleware (HTTP boundary - applied to most routes)
 * - Application layer checks (for non-HTTP contexts like jobs)
 *
 * If you're writing code that uses AuthenticatedUser, you almost certainly
 * need to either:
 * 1. Be behind EnsureUserApprovedMiddleware (routes with 'auth.supabase' middleware)
 * 2. Explicitly check hasBasicAuthorization() yourself
 * ============================================================
 */
final readonly class AuthenticatedUser
{
    /**
     * @param string $id User UUID from Supabase Auth (auth.users.id)
     * @param string $email User's email address
     * @param bool $isApproved Whether admin has approved this user (see class docblock)
     * @param string|null $roleName User's assigned role (e.g., 'admin', 'staff')
     * @param list<string>|null $departments List of department names the user belongs to
     */
    public function __construct(
        public string $id,
        public string $email,
        public bool $isApproved,
        public ?string $roleName = null,
        public ?array $departments = null,
    ) {
        Assert::uuid($id, 'User ID must be a valid UUID');
        Assert::email($email, 'User email must be valid');
    }

    /**
     * Check if user has basic authorization to access the application.
     *
     * This is the minimum authorization check required for almost all operations.
     * It verifies the user has been explicitly approved by an admin.
     *
     * Most routes enforce this via EnsureUserApprovedMiddleware, but use this
     * method for authorization checks in non-HTTP contexts (jobs, commands).
     */
    public function hasBasicAuthorization(): bool
    {
        return $this->isApproved;
    }

    /**
     * Check if user has a specific role.
     */
    public function hasRole(string $role): bool
    {
        return $this->roleName === $role;
    }

    /**
     * Check if user is an admin.
     */
    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }
}
