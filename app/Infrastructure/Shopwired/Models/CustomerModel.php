<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Models;

use App\Domain\Customer\ValueObjects\Customer;
use App\Infrastructure\Contracts\EloquentDomainMappableInterface;
use App\Infrastructure\Shopwired\Mappers\CustomerModelMapper;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Eloquent model for shopwired.customers table.
 *
 * Stores ShopWired customers synced from the API. The `external_id` is ShopWired's
 * customer ID, while `id` is our internal UUID (never exposed to Domain layer).
 *
 * Timestamps:
 * - shopwired_created_at: When customer was created in ShopWired (business data)
 * - created_at/updated_at: Laravel-managed (when synced/updated locally)
 *
 * @property string $id Internal UUID
 * @property int $external_id ShopWired customer ID
 * @property string $email Customer email (unique)
 * @property string $first_name
 * @property string $last_name
 * @property string|null $company_name
 * @property bool $is_trade Trade customer flag
 * @property bool $is_active Active customer flag
 * @property bool|null $is_credit_enabled Credit account enabled
 * @property string|null $phone
 * @property string|null $mobile_phone
 * @property bool $accepts_marketing Marketing consent
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $address_line3
 * @property string|null $city
 * @property string|null $province
 * @property string|null $postcode
 * @property string|null $notes
 * @property array<string, mixed>|null $custom_fields
 * @property CarbonImmutable $shopwired_created_at When created in ShopWired
 * @property CarbonImmutable $created_at When first synced locally
 * @property CarbonImmutable $updated_at When last updated locally
 *
 * @implements EloquentDomainMappableInterface<Customer>
 */
final class CustomerModel extends Model implements EloquentDomainMappableInterface
{
    use HasUuids;

    /**
     * The table associated with the model.
     */
    protected $table = 'shopwired.customers';

    /** Disable mass assignment protection (internal sync model, no user input). */
    protected $guarded = [];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'external_id' => 'integer',
            'is_trade' => 'boolean',
            'is_active' => 'boolean',
            'is_credit_enabled' => 'boolean',
            'accepts_marketing' => 'boolean',
            'custom_fields' => 'array',
            'shopwired_created_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Convert this Eloquent model to its corresponding Domain object.
     */
    public function toDomain(): Customer
    {
        return CustomerModelMapper::fromModel($this);
    }

    /**
     * Convert a Domain Customer to Eloquent model attributes.
     *
     * Note: Does NOT include 'external_id' - that's used as the upsert key
     * and should be handled separately by the repository.
     *
     * @param Customer $entity The domain customer to convert
     *
     * @return array<string, mixed> Attributes for Eloquent create/update
     */
    public static function fromDomainAttributes(object $entity): array
    {
        return CustomerModelMapper::toModelAttributes($entity);
    }
}
