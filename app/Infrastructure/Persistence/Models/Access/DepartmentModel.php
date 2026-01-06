<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Models\Access;

use App\Infrastructure\Persistence\Models\ProfileModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Eloquent model for the access.departments table.
 *
 * Organizational units for grouping users and permissions.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property string|null $added_by
 * @property string|null $updated_by
 * @property int $version
 */
final class DepartmentModel extends Model
{
    protected $table = 'access.departments';

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
     * Get the users in this department.
     *
     * @return BelongsToMany<ProfileModel, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            ProfileModel::class,
            'access.user_departments',
            'department_id',
            'user_id',
        );
    }

    /**
     * Get the permissions assigned to this department.
     *
     * @return BelongsToMany<PermissionModel, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            PermissionModel::class,
            'access.department_permissions',
            'department_id',
            'permission_id',
        );
    }
}
