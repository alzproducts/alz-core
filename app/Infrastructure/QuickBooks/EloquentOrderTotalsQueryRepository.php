<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Application\Contracts\QuickBooks\OrderTotalsQueryRepositoryInterface;
use App\Application\QuickBooks\OrderSync\DTOs\SourceOrderTotalsDTO;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Persistence\EloquentGateway;
use App\Infrastructure\Persistence\Models\QuickBooks\OrderTotalsViewModel;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Collection;
use Override;

final readonly class EloquentOrderTotalsQueryRepository implements OrderTotalsQueryRepositoryInterface
{
    public function __construct(
        private EloquentGateway $eloquentGateway,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function placedIn(DateTimeImmutable $from, DateTimeImmutable $before): array
    {
        // intentional: bound as text with the offset; Eloquent's own date binding drops it.
        /** @var Collection<int, OrderTotalsViewModel> $rows */
        $rows = $this->eloquentGateway->query(
            static fn(): Collection => OrderTotalsViewModel::query()
                ->where('order_placed_at', '>=', $from->format(DateTimeImmutable::ATOM))
                ->where('order_placed_at', '<', $before->format(DateTimeImmutable::ATOM))
                ->oldest('order_placed_at')
                ->oldest('reference')
                ->get(['reference', 'lifecycle_status', 'sub_total_net', 'shipping_total_net', 'tax_value']),
        );

        return \array_values($rows->map(static fn(OrderTotalsViewModel $row): SourceOrderTotalsDTO => $row->toDomain())->all());
    }
}
