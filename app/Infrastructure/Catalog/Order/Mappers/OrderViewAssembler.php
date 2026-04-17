<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Order\Mappers;

use App\Domain\Catalog\Order\ValueObjects\OrderStatus;
use App\Domain\Catalog\Order\ValueObjects\OrderStatusType;
use App\Domain\Catalog\Order\View\ValueObjects\OrderCustomerSummary;
use App\Domain\Catalog\Order\View\ValueObjects\OrderView;
use App\Infrastructure\Catalog\Order\Models\OrderViewModel;
use App\Infrastructure\Concerns\EnumLogContext;
use App\Infrastructure\Concerns\MapperHelperTrait;

/**
 * Assembles OrderView VOs from OrderViewModel rows.
 *
 * Multi-column enum reconstruction and billing-field coalescing live here so
 * OrderView stays a slim self-constructing VO.
 */
final readonly class OrderViewAssembler
{
    use MapperHelperTrait;

    public function toViewDomain(OrderViewModel $model): OrderView
    {
        return new OrderView(
            externalId: $model->external_id,
            reference: $model->reference,
            placedAt: $model->placed_at->toDateTimeImmutable(),
            total: $model->total,
            status: self::buildStatus($model),
            customer: new OrderCustomerSummary(
                email: $model->billing_email,
                fullName: $model->billing_name,
            ),
        );
    }

    private static function buildStatus(OrderViewModel $model): OrderStatus
    {
        /** @var OrderStatusType $statusType */
        $statusType = self::buildEnum(
            OrderStatusType::class,
            $model->status_name,
            OrderStatusType::Processing,
            new EnumLogContext($model->external_id, 'status_name'),
        );

        return new OrderStatus(
            id: $model->status_id,
            name: $statusType,
            type: $model->status_type,
            sortOrder: $model->status_sort_order ?? 0,
        );
    }
}
