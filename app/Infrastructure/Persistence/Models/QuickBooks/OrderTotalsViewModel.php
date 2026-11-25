<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Models\QuickBooks;

use App\Application\QuickBooks\OrderSync\DTOs\SourceOrderTotalsDTO;
use App\Domain\Catalog\Order\ValueObjects\OrderLifecycleStatus;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property int $reference
 * @property OrderLifecycleStatus $lifecycle_status
 * @property string $sub_total_net
 * @property string $shipping_total_net
 * @property string|null $tax_value
 */
final class OrderTotalsViewModel extends Model
{
    protected $table = 'shopwired.orders_deduplicated';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'reference';

    protected $guarded = [];

    /**
     * intentional: the amounts stay uncast, as the driver's exact decimal text (ADR-0022).
     *
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'reference' => 'integer',
            'lifecycle_status' => OrderLifecycleStatus::class,
        ];
    }

    public function toDomain(): SourceOrderTotalsDTO
    {
        return new SourceOrderTotalsDTO(
            reference: $this->reference,
            status: $this->lifecycle_status,
            subTotalNet: $this->sub_total_net,
            shippingTotalNet: $this->shipping_total_net,
            taxValue: $this->tax_value,
        );
    }
}
