<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Models\Auth;

use App\Infrastructure\Persistence\Models\Access\DepartmentModel;
use App\Infrastructure\Persistence\Models\Access\PermissionModel;
use App\Infrastructure\Persistence\Models\Access\RoleModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for the profiles table.
 *
 * Profiles are linked 1:1 to auth.users (Supabase-managed).
 * Profile creation happens via Supabase trigger on user signup.
 *
 * @property string $id UUID matching auth.users.id
 * @property string $first_name
 * @property string|null $last_name
 * @property string|null $avatar_url
 * @property string $role user_role enum (unauthorized, guest, standard, manager, admin)
 * @property bool $is_approved
 * @property CarbonImmutable|null $last_sign_in
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class ProfileModel extends Model
{
    use HasUuids;

    protected $table = 'profiles';

    public $incrementing = false;

    protected $keyType = 'string';

    // Timestamps managed by Supabase database triggers, not Eloquent.
    // The profiles table has created_at/updated_at columns, but they are
    // maintained by PostgreSQL triggers in the Supabase schema.
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_approved' => 'boolean',
            'last_sign_in' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get the user's assigned role (via access.user_roles pivot).
     *
     * @return BelongsToMany<RoleModel, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            RoleModel::class,
            'access.user_roles',
            'user_id',
            'role_id',
        );
    }

    /**
     * Get the user's departments (via access.user_departments pivot).
     *
     * @return BelongsToMany<DepartmentModel, $this>
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(
            DepartmentModel::class,
            'access.user_departments',
            'user_id',
            'department_id',
        );
    }

    /**
     * Get the user's direct permissions (via access.user_permissions pivot).
     *
     * @return BelongsToMany<PermissionModel, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            PermissionModel::class,
            'access.user_permissions',
            'user_id',
            'permission_id',
        )->withPivot(['expires_at', 'reason']);
    }

    /**
     * Get the user's API keys.
     *
     * @return HasMany<UserApiKeyModel, $this>
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(UserApiKeyModel::class, 'user_id', 'id');
    }

}
