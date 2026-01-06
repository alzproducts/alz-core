<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for the user_api_keys table.
 *
 * Stores encrypted API keys (AES-256-GCM) for third-party services.
 * Users can store their own ClickUp, HelpScout API keys.
 *
 * RLS: Users can only manage their own keys.
 *
 * @property string $id
 * @property string $user_id
 * @property string $service 'clickup' | 'helpscout'
 * @property string $encrypted_key AES-256-GCM encrypted key
 * @property string|null $added_by
 * @property string|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $expires_at
 * @property bool $is_valid
 */
final class UserApiKeyModel extends Model
{
    use HasUuids;

    protected $table = 'user_api_keys';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_valid' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /**
     * Get the user who owns this API key.
     *
     * @return BelongsTo<ProfileModel, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(ProfileModel::class, 'user_id', 'id');
    }
}
