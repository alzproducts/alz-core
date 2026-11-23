<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Models\QuickBooks;

use App\Application\QuickBooks\OrderSync\Enums\MappingKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property int $id
 * @property MappingKey $key
 * @property string $qbo_entity
 * @property non-empty-string|null $qbo_ref_id
 * @property string|null $qbo_ref_name
 * @property string|null $realm_id
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class QuickBooksMappingModel extends Model
{
    protected $table = 'quickbooks.mappings';

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'key' => MappingKey::class,
            'resolved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
