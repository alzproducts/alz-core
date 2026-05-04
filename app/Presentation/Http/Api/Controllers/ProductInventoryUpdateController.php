<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Controllers;

use App\Application\Inventory\UseCases\UpdateInventoryFieldsUseCase;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\InvalidSkuException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Inventory\Commands\UpdateInventoryFieldsCommand;
use App\Presentation\Http\Api\DTOs\UpdateInventoryItemDTO;
use App\Presentation\Http\Api\DTOs\UpdateInventoryRequestDTO;
use App\Presentation\Http\Api\Responses\BulkUpdateResponseDTO;

/**
 * PUT /api/products/inventory — bulk update Linnworks inventory fields.
 *
 * Only `minimum_level` is exposed; JIT is wired throughout but blocked by the current
 * Linnworks subscription. Re-add `jit` to UpdateInventoryItemDTO once upgraded.
 */
final readonly class ProductInventoryUpdateController
{
    public function __construct(
        private UpdateInventoryFieldsUseCase $useCase,
    ) {}

    /**
     * @throws InvalidSkuException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function update(UpdateInventoryRequestDTO $data): BulkUpdateResponseDTO
    {
        /** @var non-empty-list<UpdateInventoryFieldsCommand> $commands */
        $commands = \array_map(
            static fn(UpdateInventoryItemDTO $item): UpdateInventoryFieldsCommand => $item->toCommand(),
            \iterator_to_array($data->items, preserve_keys: false),
        );

        $result = $this->useCase->execute($commands);

        return BulkUpdateResponseDTO::fromBatchUpdateResult($result);
    }
}
