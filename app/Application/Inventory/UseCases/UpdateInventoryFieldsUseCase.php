<?php

declare(strict_types=1);

namespace App\Application\Inventory\UseCases;

use App\Application\Contracts\Linnworks\InventoryFieldUpdateClientInterface;
use App\Application\Contracts\Linnworks\LinnworksSyncDispatcherInterface;
use App\Application\Contracts\Linnworks\StockItemRepositoryInterface;
use App\Application\Results\BatchUpdateResult;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\PermanentApiFailure;
use App\Domain\Exceptions\Api\TransientApiFailure;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Inventory\Commands\UpdateInventoryFieldsCommand;
use App\Domain\Inventory\ValueObjects\InventoryFieldUpdate;
use Psr\Log\LoggerInterface;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * Bulk update Linnworks inventory fields (JIT, MinimumLevel) for one or more SKUs.
 *
 * Linnworks `UpdateInventoryItemField` is per-SKU per-field — this UseCase loops
 * the API call per command and accumulates per-item results so a single bad SKU
 * does not abort the batch. After the API loop, the local stock_items mirror is
 * updated for succeeded SKUs in a single bulk write. If the local write fails,
 * succeeded items are demoted to permanent failures and per-item reconciliation
 * sync jobs are dispatched to repair the divergence asynchronously.
 */
final readonly class UpdateInventoryFieldsUseCase
{
    public function __construct(
        private InventoryFieldUpdateClientInterface $fieldUpdateClient,
        private StockItemRepositoryInterface $stockItemRepository,
        private LinnworksSyncDispatcherInterface $syncDispatcher,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param list<UpdateInventoryFieldsCommand> $commands
     *
     * @return BatchUpdateResult<string>
     *
     * @throws DatabaseOperationFailedException Surfaces only from the failure-path resolver call (bulk-write failures are demoted into the result)
     * @throws DuplicateRecordException Surfaces only from the failure-path resolver call (bulk-write failures are demoted into the result)
     * @throws ExternalServiceUnavailableException Surfaces only from the failure-path resolver call (bulk-write failures are demoted into the result)
     */
    public function execute(array $commands): BatchUpdateResult
    {
        Assert::notEmpty($commands, 'At least one inventory update command is required');

        $this->logStart(\count($commands));

        [$succeeded, $permanentFailures, $temporaryFailures] = $this->performApiUpdates($commands);

        $result = $this->updateLocalDatabase(
            total: \count($commands),
            succeeded: $succeeded,
            permanentFailures: $permanentFailures,
            temporaryFailures: $temporaryFailures,
        );

        $this->logResult($result);

        return $result;
    }

    /**
     * Loop the per-SKU Linnworks API call, partitioning into succeeded / permanent / temporary failures.
     *
     * @param non-empty-list<UpdateInventoryFieldsCommand> $commands
     *
     * @return array{
     *     0: list<UpdateInventoryFieldsCommand>,
     *     1: list<array{identifier: string, error: string}>,
     *     2: list<array{identifier: string, error: string}>
     * }
     */
    private function performApiUpdates(array $commands): array
    {
        $succeeded = [];
        $permanentFailures = [];
        $temporaryFailures = [];

        foreach ($commands as $command) {
            try {
                $this->fieldUpdateClient->updateFields($command->sku, null, ...$command->updates);
                $succeeded[] = $command;
            } catch (PermanentApiFailure $e) {
                $permanentFailures[] = ['identifier' => $command->sku->value, 'error' => $e->getMessage()];
            } catch (TransientApiFailure $e) {
                $temporaryFailures[] = ['identifier' => $command->sku->value, 'error' => $e->getMessage()];
            } catch (Throwable $e) {
                $permanentFailures[] = ['identifier' => $command->sku->value, 'error' => $e->getMessage()];
            }
        }

        return [$succeeded, $permanentFailures, $temporaryFailures];
    }

    /**
     * @param int<0, max> $total
     * @param list<UpdateInventoryFieldsCommand> $succeeded
     * @param list<array{identifier: string, error: string}> $permanentFailures
     * @param list<array{identifier: string, error: string}> $temporaryFailures
     *
     * @return BatchUpdateResult<string>
     *
     * @throws DatabaseOperationFailedException When the failure-path resolver call fails after a bulk-write failure
     * @throws DuplicateRecordException When the failure-path resolver call fails after a bulk-write failure
     * @throws ExternalServiceUnavailableException When the failure-path resolver call is unavailable
     */
    private function updateLocalDatabase(
        int $total,
        array $succeeded,
        array $permanentFailures,
        array $temporaryFailures,
    ): BatchUpdateResult {
        if ($succeeded === []) {
            return new BatchUpdateResult($total, 0, $permanentFailures, $temporaryFailures);
        }

        try {
            $this->stockItemRepository->bulkUpdateInventoryFieldsBySkus(self::buildUpdatesBySku($succeeded));

            return new BatchUpdateResult($total, \count($succeeded), $permanentFailures, $temporaryFailures);
        } catch (DatabaseOperationFailedException|DuplicateRecordException|ExternalServiceUnavailableException $e) {
            $this->logDbWriteFailure(\count($succeeded), $e);

            return $this->handleDbWriteFailure($total, $succeeded, $permanentFailures, $temporaryFailures);
        }
    }

    /**
     * Build the SKU → updates map for the bulk repository call.
     *
     * @param non-empty-list<UpdateInventoryFieldsCommand> $succeeded
     *
     * @return array<string, list<InventoryFieldUpdate>>
     */
    private static function buildUpdatesBySku(array $succeeded): array
    {
        $map = [];
        foreach ($succeeded as $command) {
            $map[$command->sku->value] = $command->updates;
        }
        return $map;
    }

    /**
     * Demote succeeded items to permanent failures and dispatch reconciliation syncs.
     *
     * @param int<0, max> $total
     * @param non-empty-list<UpdateInventoryFieldsCommand> $succeeded
     * @param list<array{identifier: string, error: string}> $permanentFailures
     * @param list<array{identifier: string, error: string}> $temporaryFailures
     *
     * @return BatchUpdateResult<string>
     *
     * @throws DatabaseOperationFailedException When the resolver call fails
     * @throws DuplicateRecordException When the resolver call fails
     * @throws ExternalServiceUnavailableException When the resolver call is unavailable
     */
    private function handleDbWriteFailure(
        int $total,
        array $succeeded,
        array $permanentFailures,
        array $temporaryFailures,
    ): BatchUpdateResult {
        $this->dispatchReconciliationSyncsForSucceeded($succeeded);

        return new BatchUpdateResult(
            total: $total,
            succeeded: 0,
            permanentFailures: [...$permanentFailures, ...self::buildDbDemotionFailures($succeeded)],
            temporaryFailures: $temporaryFailures,
        );
    }

    /**
     * Resolve succeeded SKUs to stock_item_ids and dispatch a reconciliation sync per resolved Guid.
     *
     * @param non-empty-list<UpdateInventoryFieldsCommand> $succeeded
     *
     * @throws DatabaseOperationFailedException When the resolver call fails
     * @throws DuplicateRecordException When the resolver call fails
     * @throws ExternalServiceUnavailableException When the resolver call is unavailable
     */
    private function dispatchReconciliationSyncsForSucceeded(array $succeeded): void
    {
        $skus = \array_map(static fn(UpdateInventoryFieldsCommand $c): Sku => $c->sku, $succeeded);
        $stockItemIdsBySku = $this->stockItemRepository->resolveStockItemIdsBySkus(...$skus);

        foreach ($stockItemIdsBySku as $stockItemId) {
            $this->syncDispatcher->dispatchStockItemSync($stockItemId);
        }
    }

    /**
     * @param non-empty-list<UpdateInventoryFieldsCommand> $succeeded
     *
     * @return non-empty-list<array{identifier: string, error: string}>
     */
    private static function buildDbDemotionFailures(array $succeeded): array
    {
        return \array_map(
            static fn(UpdateInventoryFieldsCommand $c): array => [
                'identifier' => $c->sku->value,
                'error' => 'Local DB write failed; reconciliation sync dispatched',
            ],
            $succeeded,
        );
    }

    private function logDbWriteFailure(int $count, DatabaseOperationFailedException|DuplicateRecordException|ExternalServiceUnavailableException $e): void
    {
        $this->logger->error('Local DB write failed for Linnworks-updated inventory — frontend will show stale data until reconciliation sync', [
            'count' => $count,
            'error' => $e->getMessage(),
        ]);
    }

    private function logStart(int $count): void
    {
        $this->logger->info('Bulk updating Linnworks inventory fields', [
            'count' => $count,
        ]);
    }

    /**
     * @param BatchUpdateResult<string> $result
     */
    private function logResult(BatchUpdateResult $result): void
    {
        $this->logger->info('Bulk Linnworks inventory field update complete', [
            'total' => $result->total,
            'succeeded' => $result->succeeded,
            'failed' => $result->failed(),
        ]);
    }
}
