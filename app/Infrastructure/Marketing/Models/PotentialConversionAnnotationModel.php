<?php

declare(strict_types=1);

namespace App\Infrastructure\Marketing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Eloquent model for `marketing.potential_conversion_annotations`.
 *
 * Source-agnostic annotation layer keyed by `source_id` — a bare UUID that is either a contact
 * submission id or a call id (globally unique across both sources, no FK). Read access happens
 * via the dashboard query repository (LEFT JOIN into the unified view); this model exists purely
 * as a write target for the partial-patch upsert path.
 *
 * @property string $id UUID primary key
 * @property string $source_id Contact submission id or call id (no FK — validated at the application layer)
 * @property bool|null $is_potential_quote
 * @property string|null $notes
 * @property CarbonImmutable|null $quoted_at
 * @property CarbonImmutable|null $dismissed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class PotentialConversionAnnotationModel extends Model
{
    use HasUuids;

    protected $table = 'marketing.potential_conversion_annotations';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'is_potential_quote' => 'boolean',
            'quoted_at' => 'immutable_datetime',
            'dismissed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
