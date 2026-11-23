<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Repositories;

use App\Application\Contracts\Shopwired\OrderStatusSummaryQueryRepositoryInterface;
use App\Application\Shopwired\DTOs\OrderStatusSummaryDTO;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Persistence\EloquentGateway;
use App\Infrastructure\Shopwired\Models\OrderModel;
use Override;

final class OrderStatusSummaryQueryRepository implements OrderStatusSummaryQueryRepositoryInterface
{
    /** @var class-string<OrderModel> */
    private const string MODEL_CLASS = OrderModel::class;

    private const int STATUS_NOT_PAID = 73875;

    private const int STATUS_PAID = 73877;

    private const int STATUS_RECEIVED = 180255;

    private const int UNPAID_WINDOW_DAYS = 700;

    private const string SUMMARY_SQL = <<<'SQL'
        SELECT
            count(*) FILTER (WHERE status_id = ?) AS pending_paid_count,
            count(*) FILTER (WHERE status_id = ?) AS errored_paid_count,
            coalesce(
                string_agg(reference::text, ',' ORDER BY order_placed_at, external_id)
                    FILTER (WHERE status_id = ? AND order_placed_at >= now() - make_interval(days => ?)),
                ''
            ) AS unpaid_order_references
        FROM shopwired.orders_deduplicated
        WHERE status_id IN (?, ?, ?)
            AND is_archived = false
        SQL;

    public function __construct(
        private readonly EloquentGateway $eloquentGateway,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function getSummary(): OrderStatusSummaryDTO
    {
        /** @var object{pending_paid_count: int, errored_paid_count: int, unpaid_order_references: string} $row */
        $row = $this->eloquentGateway->query(static fn(): mixed => self::MODEL_CLASS::query()
            ->getConnection()
            ->selectOne(self::SUMMARY_SQL, [
                self::STATUS_PAID,
                self::STATUS_RECEIVED,
                self::STATUS_NOT_PAID,
                self::UNPAID_WINDOW_DAYS,
                self::STATUS_NOT_PAID,
                self::STATUS_PAID,
                self::STATUS_RECEIVED,
            ]));

        return new OrderStatusSummaryDTO(
            pendingPaidCount: $row->pending_paid_count,
            unpaidOrderReferences: $row->unpaid_order_references === '' ? [] : \explode(',', $row->unpaid_order_references),
            erroredPaidCount: $row->errored_paid_count,
        );
    }
}
