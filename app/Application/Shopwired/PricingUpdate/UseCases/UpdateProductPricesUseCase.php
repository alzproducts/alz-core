<?php

declare(strict_types=1);

namespace App\Application\Shopwired\PricingUpdate\UseCases;

use App\Application\Catalog\RetailPricing\UseCases\UpdateProductRetailPricesUseCase;
use App\Application\Shopwired\PricingUpdate\Results\FailedPriceUpdateResult;
use App\Application\Shopwired\PricingUpdate\Results\PriceUpdateResult;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\Product\Commands\UpdatePriceCommand;
use App\Domain\Catalog\Product\Commands\UpdateRetailPriceCommand;
use App\Domain\Catalog\Product\ValueObjects\SaleSettings;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\ValidationFailedException;
use App\Domain\ValueObjects\IntId;
use Psr\Log\LoggerInterface;

/**
 * Orchestrate price updates by splitting commands between selling and retail paths.
 *
 * Commands with price/salePrice → UpdateProductSellingPricesUseCase (batch POST)
 * Commands with rrp → UpdateProductRetailPricesUseCase (DB write + PUT reconciliation)
 * A command can appear in both paths if it sets both selling price and RRP.
 */
final readonly class UpdateProductPricesUseCase
{
    public function __construct(
        private UpdateProductSellingPricesUseCase $sellingPricesUseCase,
        private UpdateProductRetailPricesUseCase $retailPricesUseCase,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param IntId $productId The product these SKUs belong to
     * @param list<UpdatePriceCommand> $skuUpdates Price changes (all SKUs must belong to same product)
     * @param SaleSettings|null $saleSettings Optional sale metadata
     *
     * @throws ResourceNotFoundException When the SKU's product is not found locally
     * @throws InvalidApiResponseException When API response parsing fails
     * @throws ExternalServiceUnavailableException When API or DB unavailable
     * @throws DatabaseOperationFailedException When local product lookup fails
     * @throws DuplicateRecordException On sale settings DB constraint violation
     * @throws InvalidCustomFieldValueException When custom field mapping fails
     * @throws ValidationFailedException When any submitted price fails VAT round-trip check
     */
    public function execute(
        IntId $productId,
        array $skuUpdates,
        ?SaleSettings $saleSettings = null,
    ): PriceUpdateResult {
        $this->logger->info('Starting price update orchestration', [
            'product_id' => $productId->value, 'command_count' => \count($skuUpdates),
        ]);

        [$sellingCommands, $rrpCommands] = self::partitionCommands($skuUpdates);
        $result = PriceUpdateResult::merge(
            $sellingCommands !== [] ? $this->sellingPricesUseCase->execute($sellingCommands, $saleSettings) : null,
            $rrpCommands !== [] ? $this->executeRetailPrices($productId, $rrpCommands) : null,
        );

        $this->logger->info('Price update orchestration completed', [
            'product_id' => $productId->value, 'selling_commands' => \count($sellingCommands),
            'rrp_commands' => \count($rrpCommands), 'succeeded' => $result->succeeded,
        ]);
        return $result;
    }

    /**
     * Execute retail price update, translating DB failures into a failed result.
     *
     * @param list<UpdateRetailPriceCommand> $commands
     */
    private function executeRetailPrices(IntId $productId, array $commands): PriceUpdateResult
    {
        try {
            return $this->retailPricesUseCase->execute($productId, $commands);
        } catch (DatabaseOperationFailedException|DuplicateRecordException|ExternalServiceUnavailableException $e) {
            $this->logger->error('Retail price bulk upsert failed', [
                'product_id' => $productId->value, 'rrp_commands' => \count($commands), 'exception' => $e->getMessage(),
            ]);
            return new PriceUpdateResult(
                total: \count($commands),
                succeeded: 0,
                permanentFailures: \array_map(
                    static fn(UpdateRetailPriceCommand $cmd): FailedPriceUpdateResult => new FailedPriceUpdateResult(
                        sku: $cmd->sku,
                        error: $e->getMessage(),
                    ),
                    $commands,
                ),
            );
        }
    }

    /**
     * Partition commands into selling price and retail price paths.
     *
     * A command can appear in both lists if it has both price/salePrice and rrp.
     *
     * @param list<UpdatePriceCommand> $commands
     *
     * @return array{list<UpdatePriceCommand>, list<UpdateRetailPriceCommand>}
     */
    private static function partitionCommands(array $commands): array
    {
        $selling = [];
        $rrp = [];

        foreach ($commands as $command) {
            if ($command->price !== null || $command->salePrice !== null) {
                $selling[] = $command;
            }
            if ($command->rrp !== null) {
                $rrp[] = new UpdateRetailPriceCommand(sku: $command->sku, rrp: $command->rrp);
            }
        }

        return [$selling, $rrp];
    }
}
