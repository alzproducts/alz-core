<?php

declare(strict_types=1);

namespace App\Application\Checkout\UseCases;

use App\Application\Checkout\Commands\BasketSnapshotCommand;
use App\Application\Contracts\Checkout\BasketSnapshotRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

/**
 * Captures a pre-checkout basket snapshot for later fuzzy-matching to a completed order.
 *
 * Logs metadata only (hashed IP, presence of optional fields) to avoid PII in logs.
 */
final readonly class CaptureBasketSnapshotUseCase
{
    public function __construct(
        private BasketSnapshotRepositoryInterface $repository,
        private LoggerInterface $logger,
    ) {}

    /**
     * Persist the snapshot.
     *
     * @throws DatabaseOperationFailedException On insert failure
     * @throws DuplicateRecordException On unique constraint violation
     * @throws ExternalServiceUnavailableException On transient database failure
     */
    public function execute(BasketSnapshotCommand $snapshot): void
    {
        $this->logSnapshotReceived($snapshot);

        $id = $this->repository->save($snapshot);

        $this->logSnapshotPersisted($id);
    }

    private function logSnapshotReceived(BasketSnapshotCommand $snapshot): void
    {
        $this->logger->info('Basket snapshot received', [
            'ip_hash' => \hash('sha256', $snapshot->ipAddress),
            'basket_total' => $snapshot->basketTotal->formatGross(),
            'has_shipping_method' => $snapshot->shippingMethodId !== null,
            'has_delivery_date' => $snapshot->deliveryDate !== null,
            'has_gift_note' => $snapshot->giftNote !== null,
            'has_vat_relief' => $snapshot->vatRelief !== null,
        ]);
    }

    private function logSnapshotPersisted(string $id): void
    {
        $this->logger->info('Basket snapshot persisted', [
            'snapshot_id' => $id,
        ]);
    }
}
