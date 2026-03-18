<?php

declare(strict_types=1);

namespace App\Application\Shopwired\PricingUpdate\UseCases;

use App\Application\Contracts\Shopwired\PriceUpdateClientInterface;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Shopwired\PricingUpdate\Results\BatchApiResult;
use App\Application\Shopwired\PricingUpdate\Results\PreFlightValidationResult;
use App\Application\Shopwired\PricingUpdate\Results\PriceUpdateResult;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\Product\Commands\UpdatePriceCommand;
use App\Domain\Catalog\Product\Events\ProductPricingUpdatedEvent;
use App\Domain\Catalog\Product\Events\SkuRetailPricingUpdatedEvent;
use App\Domain\Catalog\Product\Validators\HasValidRetailPricingValidator;
use App\Domain\Catalog\Product\Validators\PriceChangedValidator;
use App\Domain\Catalog\Product\Validators\SkuBelongsToProductValidator;
use App\Domain\Catalog\Product\ValueObjects\PriceUpdateItemResult;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\ProductRetailPricing;
use App\Domain\Catalog\Product\ValueObjects\ResolvedPriceUpdate;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\PartialBatchFailureException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Api\TransientApiFailure;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\IntId;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
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
 * 4. Dispatch events for confirmed updates
 * 5. Return structured result
 */
final readonly class UpdateProductPricesUseCase
{
    public function __construct(
        private PriceUpdateClientInterface $priceClient,
        private ProductRepositoryInterface $productRepo,
        private Dispatcher $events,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param list<UpdatePriceCommand> $skuUpdates Price changes (all SKUs must belong to same product)
     *
     * @throws ResourceNotFoundException When the SKU's product is not found locally
     * @throws InvalidApiRequestException When all API chunks fail (programming error)
     * @throws AuthenticationExpiredException When all API chunks fail (auth)
     * @throws ExternalServiceUnavailableException When all API chunks fail or DB unavailable
     * @throws InvalidApiResponseException When all API chunks fail (contract violation)
     * @throws DatabaseOperationFailedException When local product lookup fails
     * @throws InvalidCustomFieldValueException When custom field mapping fails during product lookup
     */
    public function execute(array $skuUpdates): PriceUpdateResult
    {
        Assert::notEmpty($skuUpdates, 'At least one SKU update is required');

        $total = \count($skuUpdates);

        // 1. Resolve owning product and build current pricing map
        $product = $this->productRepo->getProductByAnySku($skuUpdates[0]->sku);
        $productId = IntId::fromTrusted($product->id);
        $currentPrices = self::buildPricingMap($product);

        // 2. Pre-flight validation
        $preFlight = $this->validateCommands($skuUpdates, $product, $currentPrices, $productId);

        if (! $preFlight->hasValidated()) {
            return new PriceUpdateResult(
                total: $total,
                succeeded: 0,
                skipped: $preFlight->skipped,
                permanentFailures: $preFlight->permanentFailures,
            );
        }

        // 3. Send to API
        $commands = \array_map(
            static fn(ResolvedPriceUpdate $r): UpdatePriceCommand => $r->command,
            $preFlight->validated,
        );
        $apiResult = $this->sendToApi($commands);

        // 4. Dispatch events for confirmed updates
        if ($apiResult->updatedSkus !== []) {
            $this->dispatchEvents($productId, $apiResult->updatedSkus, $preFlight->resolvedBySku());
        }

        /** @var list<array{sku: string, error: string}> $allPermanent */
        $allPermanent = [...$preFlight->permanentFailures, ...$apiResult->permanentFailures];

        $this->logger->info('Product price update completed', [
            'product_id' => $productId->value,
            'total' => $total,
            'succeeded' => $apiResult->succeeded,
            'skipped' => \count($preFlight->skipped),
            'permanent_failures' => \count($allPermanent),
            'temporary_failures' => \count($apiResult->temporaryFailures),
        ]);

        return new PriceUpdateResult(
            total: $total,
            succeeded: $apiResult->succeeded,
            skipped: $preFlight->skipped,
            permanentFailures: $allPermanent,
            temporaryFailures: $apiResult->temporaryFailures,
        );
    }

    // -----------------------------------------------------------------------
    // Pre-flight Validation
    // -----------------------------------------------------------------------

    /**
     * Validate commands: ownership, unchanged, price relationships.
     *
     * @param list<UpdatePriceCommand> $skuUpdates
     * @param array<string, ProductRetailPricing> $currentPrices
     */
    private function validateCommands(
        array $skuUpdates,
        Product $product,
        array $currentPrices,
        IntId $productId,
    ): PreFlightValidationResult {
        /** @var list<array{sku: string}> $skipped */
        $skipped = [];
        /** @var list<array{sku: string, error: string}> $permanentFailures */
        $permanentFailures = [];
        /** @var list<ResolvedPriceUpdate> $validated */
        $validated = [];

        // 1. SKU ownership check (batch-level, gates everything)
        $submittedSkus = \array_map(
            static fn(UpdatePriceCommand $cmd): Sku => $cmd->sku,
            $skuUpdates,
        );

        $ownershipResult = (new SkuBelongsToProductValidator(
            product: $product,
            requiredSkus: $submittedSkus,
        ))->validate();

        /** @var array<string, true> $unownedLookup */
        $unownedLookup = [];
        foreach ($ownershipResult->missingSkus() as $sku) {
            $unownedLookup[$sku->value] = true;
        }

        foreach ($skuUpdates as $command) {
            $skuValue = $command->sku->value;

            if (isset($unownedLookup[$skuValue])) {
                $permanentFailures[] = [
                    'sku' => $skuValue,
                    'error' => "SKU does not belong to product {$productId->value}",
                ];

                continue;
            }

            $currentPricing = $currentPrices[$skuValue] ?? null;
            Assert::notNull($currentPricing, "Owned SKU {$skuValue} must have pricing data");

            // Resolve effective pricing via carry-forward (single source of truth)
            $resolved = ResolvedPriceUpdate::fromCommand($command, $currentPricing);

            // 2. Skip unchanged prices (soft: failed = skip)
            $changeResult = (new PriceChangedValidator(
                proposed: $resolved->effectivePricing,
                current: $currentPricing,
            ))->validate();

            if ($changeResult->failed()) {
                $skipped[] = ['sku' => $skuValue];

                continue;
            }

            // 3. Validate price relationships (soft: failed = permanent failure)
            $pricingResult = (new HasValidRetailPricingValidator(
                pricing: $resolved->effectivePricing,
            ))->validate();

            if ($pricingResult->failed()) {
                $permanentFailures[] = ['sku' => $skuValue, 'error' => $pricingResult->reason()];

                continue;
            }

            $validated[] = $resolved;
        }

        return new PreFlightValidationResult(
            validated: $validated,
            skipped: $skipped,
            permanentFailures: $permanentFailures,
        );
    }

    // -----------------------------------------------------------------------
    // API Communication
    // -----------------------------------------------------------------------

    /**
     * Send validated commands to the API and classify results.
     *
     * @param list<UpdatePriceCommand> $commands
     *
     * @throws InvalidApiRequestException When all chunks fail (programming error)
     * @throws AuthenticationExpiredException When all chunks fail (auth)
     * @throws ExternalServiceUnavailableException When all chunks fail (transient)
     * @throws InvalidApiResponseException When all chunks fail (contract violation)
     */
    private function sendToApi(array $commands): BatchApiResult
    {
        /** @var list<PriceUpdateItemResult> $apiResults */
        $apiResults = [];
        /** @var list<array{sku: string, error: string}> $permanentFailures */
        $permanentFailures = [];
        /** @var list<array{sku: string, error: string}> $temporaryFailures */
        $temporaryFailures = [];

        try {
            $apiResults = $this->priceClient->updatePrices($commands);
        } catch (PartialBatchFailureException $e) {
            foreach ($e->failures as $failure) {
                $entry = ['sku' => 'unknown', 'error' => $failure->getMessage()];

                if ($failure instanceof TransientApiFailure) {
                    $temporaryFailures[] = $entry;
                } else {
                    $permanentFailures[] = $entry;
                }
            }
        }

        $succeeded = 0;
        /** @var list<Sku> $updatedSkus */
        $updatedSkus = [];

        foreach ($apiResults as $result) {
            if ($result->updated) {
                $succeeded++;
                $updatedSkus[] = $result->sku;

                continue;
            }

            $permanentFailures[] = [
                'sku' => $result->sku->value,
                'error' => 'SKU not updated — not found or API rejected',
            ];
        }

        return new BatchApiResult(
            succeeded: $succeeded,
            updatedSkus: $updatedSkus,
            permanentFailures: $permanentFailures,
            temporaryFailures: $temporaryFailures,
        );
    }

    // -----------------------------------------------------------------------
    // Pricing Map
    // -----------------------------------------------------------------------

    /**
     * Build a pricing map from a Product VO (master + all variations).
     *
     * @return array<string, ProductRetailPricing>
     */
    private static function buildPricingMap(Product $product): array
    {
        $map = [];

        if ($product->sku !== null && $product->sku !== '') {
            $map[$product->sku] = new ProductRetailPricing(
                basePrice: Money::inclusive($product->price),
                salePrice: $product->salePrice !== null ? Money::inclusive($product->salePrice) : null,
            );
        }

        foreach ($product->variations ?? [] as $variation) {
            if ($variation->sku === null || $variation->sku === '') {
                continue;
            }

            $map[$variation->sku] = new ProductRetailPricing(
                basePrice: Money::inclusive($variation->price ?? $product->price),
                salePrice: $variation->salePrice !== null ? Money::inclusive($variation->salePrice) : null,
            );
        }

        return $map;
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
    ): void {
        foreach ($updatedSkus as $sku) {
            $resolved = $resolvedBySku[$sku->value] ?? null;
            Assert::notNull($resolved, "Updated SKU {$sku->value} must have a resolved price update");

            $this->events->dispatch(new SkuRetailPricingUpdatedEvent(
                sku: $sku,
                previousPrices: $resolved->currentPricing,
                newPrices: $resolved->effectivePricing,
            ));
        }

        $this->events->dispatch(new ProductPricingUpdatedEvent(
            productId: $productId,
            updatedSkus: $updatedSkus,
        ));
    }
}
