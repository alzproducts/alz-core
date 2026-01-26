<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Models\Access;

use App\Infrastructure\Persistence\Models\Auth\ProfileModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Eloquent model for the access.roles table.
 *
 * Roles: guest, standard, manager, admin.
 * Users have exactly one role (1:1 via user_roles).
 *
 * @property int $id
 * @property string $name 'guest' | 'standard' | 'manager' | 'admin'
 * @property string|null $description
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property string|null $added_by
 * @property string|null $updated_by
 * @property int $version
 */
final class RoleModel extends Model
{
    protected $table = 'access.roles';

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Get the users with this role.
     *
     * @return BelongsToMany<ProfileModel, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            ProfileModel::class,
            'access.user_roles',
            'role_id',
            'user_id',
        );
    }

    /**
     * Get the permissions assigned to this role.
     *
     * @return BelongsToMany<PermissionModel, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            PermissionModel::class,
            'access.role_permissions',
            'role_id',
            'permission_id',
        );
    }
}
