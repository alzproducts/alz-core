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
 * Consumer API endpoint for product inventory field updates.
 *
 * Currently only exposes `minimum_level` — `jit` is supported throughout
 * the underlying domain, client, and repository, but the API does not accept
 * it because the current Linnworks subscription doesn't include the JIT
 * feature (writes return 400 "Subscription does not have required feature").
 * To re-expose JIT once the subscription is upgraded, add a `jit` property
 * back onto `UpdateInventoryItemDTO` — see DTO docblock.
 *
 * Requires Supabase JWT authentication + approval gate.
 *
 * Returns 200 with `{total, succeeded, failures}` envelope. The endpoint is
 * partial-success: per-item Linnworks failures appear in `failures` rather
 * than aborting the whole batch. The aggregate response distinguishes only
 * `total` vs `succeeded` — the wire contract intentionally hides the
 * permanent/temporary failure split surfaced by `BatchUpdateResult`.
 */
final readonly class ProductInventoryUpdateController
{
    public function __construct(
        private UpdateInventoryFieldsUseCase $useCase,
    ) {}

    /**
     * @throws InvalidSkuException When a submitted SKU has invalid format
     * @throws DatabaseOperationFailedException Surfaces only from the failure-path resolver call (bulk-write DB failures are demoted into the response body)
     * @throws DuplicateRecordException Surfaces only from the failure-path resolver call (bulk-write DB failures are demoted into the response body)
     * @throws ExternalServiceUnavailableException When the local DB write or the failure-path resolver call is unavailable
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
