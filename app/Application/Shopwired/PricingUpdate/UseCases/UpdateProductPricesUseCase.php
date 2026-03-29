<?php

declare(strict_types=1);

namespace App\Application\Shopwired\PricingUpdate\UseCases;

use App\Application\Contracts\Shopwired\PriceUpdateClientInterface;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Contracts\Shopwired\SaleReconciliationDispatcherInterface;
use App\Application\Contracts\Shopwired\SaleSettingsRepositoryInterface;
use App\Application\Shopwired\PricingUpdate\PriceCommandPreFlightService;
use App\Application\Shopwired\PricingUpdate\Results\BatchApiResult;
use App\Application\Shopwired\PricingUpdate\Results\FailedPriceUpdateResult;
use App\Application\Shopwired\PricingUpdate\Results\PriceUpdateResult;
use App\Application\Shopwired\Services\ProductSyncService;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\Product\Commands\UpdatePriceCommand;
use App\Domain\Catalog\Product\Events\ProductPricingUpdatedEvent;
use App\Domain\Catalog\Product\Events\SkuRetailPricingUpdatedEvent;
use App\Domain\Catalog\Product\Transformers\ProductRetailPricingTransformer;
use App\Domain\Catalog\Product\ValueObjects\ResolvedPriceUpdate;
use App\Domain\Catalog\Product\ValueObjects\SaleSettings;
use App\Domain\Catalog\Product\ValueObjects\SaleSubmissionContext;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Catalog\Product\ValueObjects\SkuPriceChange;
use App\Domain\Exceptions\Api\AbstractApiException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Api\TransientApiFailure;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\ValidationFailedException;
use App\Domain\ValueObjects\IntId;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * Update retail prices for a single product's SKUs via ShopWired batch API.
 *
 * Accepts a list of SKU price commands and resolves the owning product
 * internally from the first SKU. All submitted SKUs must belong to the
 * same product.
 *
 * Flow:
 * 1. Resolve owning product from the first SKU
 * 2. Pre-flight: validate and filter commands (ownership, unchanged, price relationships)
 * 3. Send validated commands to API
 * 4. Best-effort DB sync (non-blocking)
 * 5. Dispatch events + delayed sale state reconciliation for confirmed updates
 * 6. Return structured result
 */
final readonly class UpdateProductPricesUseCase
{
    public function __construct(
        private PriceUpdateClientInterface $priceClient,
        private ProductRepositoryInterface $productRepo,
        private ProductSyncService $productSyncService,
        private SaleReconciliationDispatcherInterface $saleReconciliationDispatcher,
        private SaleSettingsRepositoryInterface $saleSettingsRepo,
        private Dispatcher $events,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param list<UpdatePriceCommand> $skuUpdates Price changes (all SKUs must belong to same product)
     * @param SaleSettings|null $saleSettings Optional sale metadata threaded to downstream listeners
     *
     * @throws ResourceNotFoundException When the SKU's product is not found locally
     * @throws InvalidApiResponseException When API response parsing fails (contract violation)
     * @throws ExternalServiceUnavailableException When API transport initialization fails
     * @throws DatabaseOperationFailedException When local product lookup fails
     * @throws DuplicateRecordException On sale settings DB constraint violation
     * @throws InvalidCustomFieldValueException When custom field mapping fails during product lookup
     * @throws ValidationFailedException When any submitted price fails VAT round-trip check
     */
    public function execute(array $skuUpdates, ?SaleSettings $saleSettings = null): PriceUpdateResult
    {
        Assert::notEmpty($skuUpdates, 'At least one SKU update is required');

        $total = \count($skuUpdates);

        // 0. VAT round-trip validation (fail-fast before any DB/API work)
        PriceCommandPreFlightService::validateVatRoundTrip($skuUpdates);

        // 1. Resolve owning product and build current pricing map
        $product = $this->productRepo->getProductByAnySku($skuUpdates[0]->sku);
        $productId = IntId::fromTrusted($product->id);
        $currentPrices = ProductRetailPricingTransformer::fromProduct($product);

        // 2. Pre-flight validation
        $preFlight = PriceCommandPreFlightService::validateCommands($skuUpdates, $product, $currentPrices);

        if (! $preFlight->hasValidated()) {
            return PriceUpdateResult::fromPhases($total, $preFlight, null);
        }

        // 2b. Persist or clear sale settings before API call so jobs always read fresh state.
        $saleSubmissionContext = $this->persistSaleState($productId, $saleSettings);

        // 3. Send to API
        $commands = \array_map(
            static fn(ResolvedPriceUpdate $r): UpdatePriceCommand => $r->command,
            $preFlight->validated,
        );
        $apiResult = $this->sendToApi($commands);

        // 4. Best-effort DB sync — must NOT block events or reconciliation
        try {
            $this->productSyncService->refreshById($productId->value);
        } catch (Throwable $e) { // @ignoreException — best-effort sync must not block events
            $this->logger->warning('Post-update product sync failed (non-blocking)', [
                'product_id' => $productId->value,
                'exception' => $e->getMessage(),
            ]);
        }

        // 5. Dispatch events + reconciliation for confirmed updates
        if ($apiResult->updatedSkus !== []) {
            $this->dispatchEvents($productId, $apiResult->updatedSkus, $preFlight->resolvedBySku(), $saleSubmissionContext);
            $this->saleReconciliationDispatcher->dispatchReconciliation($productId);
        }

        // 6. Build result and log
        $result = PriceUpdateResult::fromPhases($total, $preFlight, $apiResult);

        $this->logger->info('Product price update completed', [
            'product_id' => $productId->value,
            'total' => $result->total,
            'succeeded' => $result->succeeded,
            'skipped' => \count($result->skipped),
            'permanent_failures' => \count($result->permanentFailures),
            'temporary_failures' => \count($result->temporaryFailures),
        ]);

        return $result;
    }

    // -----------------------------------------------------------------------
    // Sale State Persistence
    // -----------------------------------------------------------------------

    /**
     * Persist or clear sale settings before the API call.
     *
     * For removals: snapshots the existing DB row for Slack context, then deletes it.
     * For additions: upserts the new settings so AddToSaleJob reads fresh data on execution.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function persistSaleState(IntId $productId, ?SaleSettings $saleSettings): ?SaleSubmissionContext
    {
        if ($saleSettings !== null && $saleSettings->removalReason !== null) {
            $existingSettings = $this->saleSettingsRepo->findByProduct($productId);
            $saleSubmissionContext = $existingSettings !== null
                ? SaleSubmissionContext::fromSaleSettings($existingSettings, $saleSettings->removalReason)
                : new SaleSubmissionContext(removalReason: $saleSettings->removalReason);
            $this->saleSettingsRepo->delete($productId);

            return $saleSubmissionContext;
        }

        if ($saleSettings !== null) {
            $this->saleSettingsRepo->save($productId, $saleSettings);
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // API Communication
    // -----------------------------------------------------------------------

    /**
     * Send validated commands to the API and classify results.
     *
     * Uses the StockClient pattern: the client returns all valid information
     * (successful batch results + transport failures), and we classify here.
     *
     * All transport failures are classified as permanent or temporary and
     * returned in the BatchApiResult. The calling Job decides retry strategy.
     *
     * @param list<UpdatePriceCommand> $commands
     *
     * @throws InvalidApiResponseException When response parsing fails (contract violation)
     * @throws ExternalServiceUnavailableException When HTTP pool initialization fails
     */
    private function sendToApi(array $commands): BatchApiResult
    {
        $clientResult = $this->priceClient->updatePrices($commands);

        // Classify transport failures as permanent/temporary
        [$permanentFailures, $temporaryFailures] = self::classifyTransportFailures(
            $clientResult->transportFailures,
        );

        // Classify per-item results from successful batches
        /** @var list<Sku> $updatedSkus */
        $updatedSkus = [];

        foreach ($clientResult->results as $result) {
            if ($result->updated) {
                $updatedSkus[] = $result->sku;

                continue;
            }

            $permanentFailures[] = new FailedPriceUpdateResult(
                sku: $result->sku,
                error: 'SKU not updated — not found or API rejected',
            );
        }

        return new BatchApiResult(
            updatedSkus: $updatedSkus,
            permanentFailures: $permanentFailures,
            temporaryFailures: $temporaryFailures,
        );
    }

    /**
     * Classify transport failures as permanent or temporary.
     *
     * @param list<AbstractApiException> $failures
     *
     * @return array{list<FailedPriceUpdateResult>, list<FailedPriceUpdateResult>} [permanent, temporary]
     */
    private static function classifyTransportFailures(array $failures): array
    {
        /** @var list<FailedPriceUpdateResult> $permanent */
        $permanent = [];
        /** @var list<FailedPriceUpdateResult> $temporary */
        $temporary = [];

        foreach ($failures as $failure) {
            $entry = new FailedPriceUpdateResult(
                sku: null,
                error: $failure->getMessage(),
            );

            if ($failure instanceof TransientApiFailure) {
                $temporary[] = $entry;
            } else {
                $permanent[] = $entry;
            }
        }

        return [$permanent, $temporary];
    }

    // -----------------------------------------------------------------------
    // Events
    // -----------------------------------------------------------------------

    /**
     * Dispatch per-SKU and per-product events for confirmed updates.
     *
     * @param list<Sku> $updatedSkus
     * @param array<string, ResolvedPriceUpdate> $resolvedBySku
     */
    private function dispatchEvents(
        IntId $productId,
        array $updatedSkus,
        array $resolvedBySku,
        ?SaleSubmissionContext $saleSubmissionContext,
    ): void {
        /** @var list<SkuPriceChange> $priceChanges */
        $priceChanges = [];

        foreach ($updatedSkus as $sku) {
            $resolved = $resolvedBySku[$sku->value] ?? null;
            Assert::notNull($resolved, "Updated SKU {$sku->value} must have a resolved price update");

            $this->events->dispatch(new SkuRetailPricingUpdatedEvent(
                productId: $productId,
                sku: $sku,
                previousPrices: $resolved->currentPricing,
                newPrices: $resolved->effectivePricing,
            ));

            $priceChanges[] = new SkuPriceChange(
                sku: $sku,
                previousPrices: $resolved->currentPricing,
                newPrices: $resolved->effectivePricing,
            );
        }

        $this->events->dispatch(new ProductPricingUpdatedEvent(
            productId: $productId,
            priceChanges: $priceChanges,
            saleSubmissionContext: $saleSubmissionContext,
        ));
    }
}
