<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for the system_cache table.
 *
 * Database-backed cache for critical system data (e.g., Linnworks API sessions).
 * Provides reliable backup when KV cache is unavailable.
 *
 * RLS: Enabled but NO policies - only service_role can access.
 *
 * @property string $key Primary key (e.g., 'linnworks:session')
 * @property array<string, mixed> $value JSON data
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class SystemCacheModel extends Model
{
    protected $table = 'system_cache';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'array',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
