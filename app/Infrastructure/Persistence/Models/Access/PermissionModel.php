<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Models\Access;

use App\Infrastructure\Persistence\Models\Auth\ProfileModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Eloquent model for the access.permissions table.
 *
 * Permissions: action + resource combinations.
 * Actions: view, create, edit, delete, export, manage, *
 *
 * @property int $id
 * @property string $action 'view' | 'create' | 'edit' | 'delete' | 'export' | 'manage' | '*'
 * @property string $resource
 * @property string|null $display_name
 * @property string|null $description
 * @property bool $is_system
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property string|null $added_by
 * @property string|null $updated_by
 * @property int $version
 */
final class PermissionModel extends Model
{
    protected $table = 'access.permissions';

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Get the roles that have this permission.
     *
     * @return BelongsToMany<RoleModel, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            RoleModel::class,
            'access.role_permissions',
            'permission_id',
            'role_id',
        );
    }

    /**
     * Get the users with this direct permission.
     *
     * @return BelongsToMany<ProfileModel, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            ProfileModel::class,
            'access.user_permissions',
            'permission_id',
            'user_id',
        )->withPivot(['expires_at', 'reason']);
    }

    /**
     * Get the departments with this permission.
     *
     * @return BelongsToMany<DepartmentModel, $this>
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(
            DepartmentModel::class,
            'access.department_permissions',
            'permission_id',
            'department_id',
        );
    }
}
