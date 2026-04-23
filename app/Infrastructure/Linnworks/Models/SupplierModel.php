<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Models;

use App\Domain\Inventory\ValueObjects\Supplier;
use App\Infrastructure\Contracts\EloquentDomainMappableInterface;
use App\Infrastructure\Linnworks\Mappers\SupplierMapper;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Eloquent model for linnworks.suppliers table.
 *
 * Stores the Linnworks master supplier directory (contact/address details).
 * Distinct from StockItemSupplierModel which stores supplier-to-stock-item junctions.
 *
 * @property string $id Internal UUID
 * @property string $pk_supplier_id Linnworks supplier GUID
 * @property string $supplier_name
 * @property string|null $contact_name
 * @property string|null $address
 * @property string|null $alternative_address
 * @property string|null $city
 * @property string|null $region
 * @property string|null $country
 * @property string|null $post_code
 * @property string|null $telephone_number
 * @property string|null $secondary_tel_number
 * @property string|null $fax_number
 * @property string|null $email
 * @property string|null $web_page
 * @property string|null $currency
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 *
 * @implements EloquentDomainMappableInterface<Supplier>
 */
final class SupplierModel extends Model implements EloquentDomainMappableInterface
{
    use HasUuids;

    protected $table = 'linnworks.suppliers';

    /** Disable mass assignment protection (internal sync model, no user input). */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function toDomain(): Supplier
    {
        return SupplierMapper::fromModel($this);
    }

    /**
     * @param Supplier $entity
     *
     * @return array<string, mixed>
     */
    public static function fromDomainAttributes(object $entity): array
    {
        return SupplierMapper::toModelAttributes($entity);
    }
}
