<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases;

use App\Application\Contracts\Shopwired\OrderStatusSummaryQueryRepositoryInterface;
use App\Application\Shopwired\DTOs\OrderStatusSummaryDTO;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

final readonly class GetOrderStatusSummaryUseCase
{
    public function __construct(
        private OrderStatusSummaryQueryRepositoryInterface $summaryRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(): OrderStatusSummaryDTO
    {
        $this->logger->info('Reading order status summary');

        $summary = $this->summaryRepository->getSummary();

        $this->logger->info('Order status summary read', [
            'pending_paid_count' => $summary->pendingPaidCount,
            'unpaid_order_count' => \count($summary->unpaidOrderReferences),
            'errored_paid_count' => $summary->erroredPaidCount,
        ]);

        return $summary;
    }
}
