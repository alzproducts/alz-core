<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Models\QuickBooks;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $id
 * @property string $environment
 * @property string $encrypted_refresh_token
 * @property CarbonImmutable $refresh_token_expires_at
 * @property CarbonImmutable|null $refresh_token_hard_expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class QuickBooksConnectionModel extends Model
{
    use HasUuids;

    protected $table = 'public.quickbooks_connections';

    protected $guarded = [];

    #[Override]
    protected function casts(): array
    {
        return [
            'refresh_token_expires_at' => 'immutable_datetime',
            'refresh_token_hard_expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
