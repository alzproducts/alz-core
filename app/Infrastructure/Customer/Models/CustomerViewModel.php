<?php

declare(strict_types=1);

namespace App\Infrastructure\Customer\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only Eloquent model for catalog.customers_view.
 *
 * Passthrough projection of shopwired.customers exposing only the fields the
 * read-side API needs. Lives in the catalog schema (read-side concern)
 * regardless of which sync source feeds it. Write operations continue via the
 * write-side Customer repository / model.
 *
 * @property string $id Internal UUID
 * @property int $external_id ShopWired customer ID
 * @property string $email Customer email
 * @property string $first_name Customer first name
 * @property string $last_name Customer last name
 * @property bool $is_trade Whether this is a trade customer
 * @property bool $is_active Whether the customer account is active
 * @property CarbonImmutable $created_at When the customer was created in ShopWired
 */
final class CustomerViewModel extends Model
{
    protected $table = 'catalog.customers_view';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'external_id' => 'integer',
            'is_trade' => 'boolean',
            'is_active' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }
}
