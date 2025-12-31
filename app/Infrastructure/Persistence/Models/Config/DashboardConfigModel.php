<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Models\Config;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for the config.dashboard table.
 *
 * Stores configuration for dashboard tables (thresholds, tags, display options).
 * Settings are JSONB, structure varies by table_name.
 *
 * RLS: Authenticated users can read, admins/managers can modify.
 *
 * @property string $id
 * @property string $table_name Identifier for the dashboard (e.g., 'hs_escalations')
 * @property array<string, mixed> $settings JSONB configuration
 * @property bool $enabled
 * @property CarbonImmutable|null $created_at
 * @property string|null $added_by
 * @property CarbonImmutable|null $updated_at
 * @property string|null $updated_by
 */
final class DashboardConfigModel extends Model
{
    use HasUuids;

    protected $table = 'config.dashboard';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'enabled' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
