<?php

declare(strict_types=1);

namespace App\Application\Inventory\UseCases;

use App\Application\Contracts\Linnworks\InventoryFieldUpdateClientInterface;
use App\Application\Contracts\Linnworks\StockItemRepositoryInterface;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Inventory\Commands\UpdateInventoryFieldsCommand;
use App\Domain\Inventory\ValueObjects\InventoryFieldUpdate;
use Psr\Log\LoggerInterface;

final readonly class UpdateVariationInventoryUseCase
{
    public function __construct(
        private InventoryFieldUpdateClientInterface $fieldUpdateClient,
        private StockItemRepositoryInterface $stockItemRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws ResourceNotFoundException When stock item not found by SKU in Linnworks
     * @throws InvalidApiRequestException When parameters invalid
     * @throws InvalidApiResponseException When API response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws DatabaseOperationFailedException On local DB write failure
     * @throws DuplicateRecordException On local DB constraint violation
     */
    public function execute(UpdateInventoryFieldsCommand $command): void
    {
        $fields = \array_map(static fn(InventoryFieldUpdate $u) => $u->field->name, $command->updates);

        $this->logger->info('Updating inventory fields', [
            'sku'    => $command->sku->value,
            'fields' => $fields,
        ]);

        $this->fieldUpdateClient->updateFields($command->sku, ...$command->updates);

        $this->syncLocalMirror($command);

        $this->logger->info('Inventory fields updated', [
            'sku'    => $command->sku->value,
            'fields' => $fields,
        ]);
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function syncLocalMirror(UpdateInventoryFieldsCommand $command): void
    {
        $affected = $this->stockItemRepository->updateInventoryFieldsBySku($command->sku, ...$command->updates);

        if ($affected === 0) {
            $this->logger->warning('Stock item not found locally after Linnworks inventory update', [
                'sku' => $command->sku->value,
            ]);
        }
    }
}
