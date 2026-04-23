<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Models\Access;

use App\Infrastructure\Persistence\Models\Auth\ProfileModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * Eloquent model for the access.user_permissions pivot table.
 *
 * Direct user-to-permission assignments with expiry support.
 * This model exists because the pivot has meaningful extra columns
 * (expires_at, reason) that we may need to query directly.
 *
 * @property string $user_id
 * @property int $permission_id
 * @property CarbonImmutable|null $expires_at
 * @property string|null $reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property string|null $added_by
 * @property string|null $updated_by
 */
final class UserPermissionModel extends Model
{
    protected $table = 'access.user_permissions';

    /**
     * Composite primary key - no single incrementing column.
     */
    public $incrementing = false;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'permission_id' => 'integer',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Get the user.
     *
     * @return BelongsTo<ProfileModel, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(ProfileModel::class, 'user_id', 'id');
    }

    /**
     * Get the permission.
     *
     * @return BelongsTo<PermissionModel, $this>
     */
    public function permission(): BelongsTo
    {
        return $this->belongsTo(PermissionModel::class, 'permission_id', 'id');
    }
}
