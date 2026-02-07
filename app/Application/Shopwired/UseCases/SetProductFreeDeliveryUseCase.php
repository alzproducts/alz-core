<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases;

use App\Application\Contracts\Shopwired\ProductIdentifierResolverInterface;
use App\Application\Contracts\Shopwired\ProductUpdateClientInterface;
use App\Application\Results\BatchUpdateResult;
use App\Domain\Catalog\Product\Commands\SetFreeDeliveryCommand;
use App\Domain\Catalog\Product\Exceptions\ProductIdentifierResolutionException;
use App\Domain\Exceptions\Api\PermanentApiFailure;
use App\Domain\Exceptions\Api\TransientApiFailure;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use Psr\Log\LoggerInterface;

/**
 * Set free delivery designation on ShopWired products.
 *
 * Processes a batch of commands with continue-on-failure semantics:
 * individual failures are logged and tracked, but processing continues
 * for remaining items.
 *
 * Failure Classification:
 * - **Permanent**: SKU not found, product not found, invalid request, auth expired
 * - **Temporary**: API unavailable, timeouts, database failures (worth retrying)
 *
 * Resolution: SKU identifiers are resolved to parent product IDs.
 * Variation SKUs will update their parent product's custom fields.
 */
final readonly class SetProductFreeDeliveryUseCase
{
    private const string CUSTOM_FIELD_NAME = 'free_delivery';

    public function __construct(
        private ProductIdentifierResolverInterface $resolver,
        private ProductUpdateClientInterface $updateClient,
        private LoggerInterface $logger,
    ) {}

    /**
     * Execute batch update of free delivery custom field.
     *
     * @param list<SetFreeDeliveryCommand> $commands Commands to process
     *
     * @return BatchUpdateResult<string|int> Result with success/failure counts
     */
    public function execute(array $commands): BatchUpdateResult
    {
        if ($commands === []) {
            return BatchUpdateResult::empty();
        }

        $total = \count($commands);
        $succeeded = 0;
        /** @var list<array{identifier: string|int, error: string}> $permanentFailures */
        $permanentFailures = [];
        /** @var list<array{identifier: string|int, error: string}> $temporaryFailures */
        $temporaryFailures = [];

        $this->logger->info('Starting free delivery batch update', ['total' => $total]);

        foreach ($commands as $command) {
            try {
                $this->processCommand($command);
                $succeeded++;

                $this->logger->debug('Updated free delivery', [
                    'identifier' => $command->identifier,
                    'type' => $command->freeDeliveryType->value,
                ]);
            } catch (ProductIdentifierResolutionException|PermanentApiFailure $e) {
                // Permanent failures: data issues or auth problems that won't resolve on retry
                $permanentFailures[] = [
                    'identifier' => $command->identifier,
                    'error' => $e->getMessage(),
                ];
                $this->logger->warning('Permanent failure updating free delivery', [
                    'identifier' => $command->identifier,
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);
            } catch (TransientApiFailure|DatabaseOperationFailedException $e) {
                // Temporary failures: service issues that may resolve on retry
                $temporaryFailures[] = [
                    'identifier' => $command->identifier,
                    'error' => $e->getMessage(),
                ];
                $this->logger->warning('Temporary failure updating free delivery', [
                    'identifier' => $command->identifier,
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('Free delivery batch update completed', [
            'total' => $total,
            'succeeded' => $succeeded,
            'permanent_failures' => \count($permanentFailures),
            'temporary_failures' => \count($temporaryFailures),
        ]);

        return new BatchUpdateResult(
            total: $total,
            succeeded: $succeeded,
            permanentFailures: $permanentFailures,
            temporaryFailures: $temporaryFailures,
        );
    }

    /**
     * Process a single command.
     *
     * @throws ProductIdentifierResolutionException When identifier cannot be resolved
     * @throws PermanentApiFailure When non-retryable API failure occurs
     * @throws TransientApiFailure When API is unavailable
     * @throws DatabaseOperationFailedException On database errors
     */
    private function processCommand(SetFreeDeliveryCommand $command): void
    {
        $productId = $this->resolver->resolveToParentProductId($command->identifier);

        $this->updateClient->updateCustomFields($productId, [
            self::CUSTOM_FIELD_NAME => $command->freeDeliveryType->toStringOrEmpty(),
        ]);
    }
}
