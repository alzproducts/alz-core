<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Models\Auth;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for the auth_allowed_emails table.
 *
 * Whitelist of specific email addresses allowed to sign up.
 * Used by auth hooks to validate new user registrations.
 *
 * RLS: Enabled but NO policies - only service_role can access.
 *
 * @property string $id
 * @property string $email
 * @property string|null $added_by UUID of profile who added this email
 * @property CarbonImmutable|null $created_at
 */
final class AuthAllowedEmailModel extends Model
{
    use HasUuids;

    protected $table = 'auth_allowed_emails';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }

}
