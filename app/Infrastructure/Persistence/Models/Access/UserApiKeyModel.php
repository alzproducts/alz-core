<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Models\Access;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Eloquent model for public.user_api_keys.
 *
 * Uses the pgsql (admin) connection — the Consumer API routes do not set an RLS
 * context, so queries must filter by user_id explicitly in the application layer.
 *
 * @property string $id
 * @property string $user_id
 * @property string $service
 * @property string $encrypted_key
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
    protected $table = 'public.user_api_keys';

    protected $connection = 'pgsql';

    protected $fillable = [
        'user_id',
        'service',
        'encrypted_key',
        'is_valid',
    ];

    #[Override]
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
}
