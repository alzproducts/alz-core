<?php

declare(strict_types=1);

namespace App\Application\Catalog\RetailPricing\UseCases;

use App\Application\Contracts\Catalog\ProductExtraDataRepositoryInterface;
use App\Application\Shopwired\PricingUpdate\Results\FailedPriceUpdateResult;
use App\Application\Shopwired\PricingUpdate\Results\PriceUpdateResult;
use App\Application\Shopwired\PricingUpdate\UseCases\ReconcileShopwiredComparePriceUseCase;
use App\Domain\Catalog\Product\Commands\UpdateRetailPriceCommand;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\IntId;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Write per-SKU RRP to the database and trigger ShopWired reconciliation.
 *
 * For each command:
 * - Money::inclusive(0) = clear RRP (set to null in DB)
 * - Any other value = set RRP
 *
 * After all DB writes, reconciliation is called best-effort (failures are logged, not thrown).
 */
final readonly class UpdateProductRetailPricesUseCase
{
    public function __construct(
        private ProductExtraDataRepositoryInterface $extraDataRepo,
        private ReconcileShopwiredComparePriceUseCase $reconcileUseCase,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param IntId $productId The product these SKUs belong to
     * @param list<UpdateRetailPriceCommand> $commands Per-SKU RRP updates
     */
    public function execute(IntId $productId, array $commands): PriceUpdateResult
    {
        if ($commands === []) {
            return new PriceUpdateResult(total: 0, succeeded: 0);
        }

        [$succeeded, $failures] = $this->writeRrpPerSku($commands);

        if ($succeeded > 0) {
            $this->reconcileBestEffort($productId);
        }

        return $this->buildResult($productId, \count($commands), $succeeded, $failures);
    }

    /**
     * @param list<FailedPriceUpdateResult> $failures
     */
    private function buildResult(IntId $productId, int $total, int $succeeded, array $failures): PriceUpdateResult
    {
        $this->logger->info('Retail price update completed', [
            'product_id' => $productId->value,
            'total' => $total,
            'succeeded' => $succeeded,
            'failures' => \count($failures),
        ]);

        return new PriceUpdateResult(
            total: $total,
            succeeded: $succeeded,
            permanentFailures: $failures,
        );
    }

    /**
     * @param list<UpdateRetailPriceCommand> $commands
     *
     * @return array{int, list<FailedPriceUpdateResult>}
     */
    private function writeRrpPerSku(array $commands): array
    {
        $succeeded = 0;
        /** @var list<FailedPriceUpdateResult> $failures */
        $failures = [];

        foreach ($commands as $command) {
            $resolvedRrp = $command->rrp->isZero() ? null : $command->rrp;
            try {
                $this->extraDataRepo->upsertRrp($command->sku, $resolvedRrp);
                $succeeded++;
            } catch (DatabaseOperationFailedException|DuplicateRecordException|ExternalServiceUnavailableException $e) {
                $failures[] = new FailedPriceUpdateResult(sku: $command->sku, error: $e->getMessage());
            }
        }

        return [$succeeded, $failures];
    }

    private function reconcileBestEffort(IntId $productId): void
    {
        try {
            $this->reconcileUseCase->execute($productId);
        } catch (Throwable $e) { // @ignoreException — reconciliation is best-effort
            $this->logger->warning('ShopWired comparePrice reconciliation failed (non-blocking)', [
                'product_id' => $productId->value,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
