<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Models;

use App\Domain\Catalog\Product\ValueObjects\SaleSettings;
use App\Infrastructure\Contracts\EloquentDomainMappableInterface;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Eloquent model for shopwired.product_sale_settings table.
 *
 * Stores sale metadata for products currently in a sale.
 * One row per product — upserted on add-to-sale, deleted on removal.
 *
 * @property string $id Internal UUID
 * @property int $product_external_id ShopWired product external ID
 * @property string $sale_reason Why the product is on sale
 * @property string|null $sale_comments Additional sale comments
 * @property CarbonImmutable|null $sale_start_date When the sale started
 * @property CarbonImmutable|null $sale_end_date When the sale ends
 * @property int|null $sale_ends_stock Units-sold threshold that ends the sale
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 *
 * @implements EloquentDomainMappableInterface<SaleSettings>
 */
final class ProductSaleSettingsModel extends Model implements EloquentDomainMappableInterface
{
    use HasUuids;

    protected $table = 'shopwired.product_sale_settings';

    /** Disable mass assignment protection (internal orchestration model, no user input). */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'product_external_id' => 'integer',
            'sale_ends_stock' => 'integer',
            'sale_start_date' => 'immutable_date',
            'sale_end_date' => 'immutable_date',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function toDomain(): SaleSettings
    {
        return new SaleSettings(
            saleReason: $this->sale_reason,
            saleComments: $this->sale_comments !== '' ? $this->sale_comments : null,
            saleStartDate: $this->sale_start_date?->toDateTimeImmutable(),
            saleEndDate: $this->sale_end_date?->toDateTimeImmutable(),
            saleEndsStock: $this->sale_ends_stock,
        );
    }

    /**
     * @param SaleSettings $entity
     *
     * @return array<string, mixed>
     */
    public static function fromDomainAttributes(object $entity): array
    {
        return [
            'sale_reason' => $entity->saleReason,
            'sale_comments' => $entity->saleComments,
            'sale_start_date' => $entity->saleStartDate?->format('Y-m-d'),
            'sale_end_date' => $entity->saleEndDate?->format('Y-m-d'),
            'sale_ends_stock' => $entity->saleEndsStock,
        ];
    }
}
