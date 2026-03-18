<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases;

use App\Application\Contracts\Shopwired\PriceUpdateClientInterface;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Shopwired\Results\PriceUpdateResult;
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
 * 2. Validate and filter commands (ownership, unchanged, price relationships)
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

        // 2. Validate and filter
        $validated = $this->validateCommands($skuUpdates, $product, $currentPrices, $productId);

        if ($validated['commands'] === []) {
            return new PriceUpdateResult(
                total: $total,
                succeeded: 0,
                skipped: $validated['skipped'],
                permanentFailures: $validated['permanentFailures'],
            );
        }

        // 3. Send to API and process results
        $apiOutcome = $this->sendToApi($validated['commands']);

        // 4. Dispatch events for confirmed updates
        if ($apiOutcome['updatedSkus'] !== []) {
            $this->dispatchEvents($productId, $apiOutcome['updatedSkus'], $currentPrices, $validated['commandsBySku']);
        }

        /** @var list<array{sku: string, error: string}> $allPermanent */
        $allPermanent = [...$validated['permanentFailures'], ...$apiOutcome['permanentFailures']];

        $this->logger->info('Product price update completed', [
            'product_id' => $productId->value,
            'total' => $total,
            'succeeded' => $apiOutcome['succeeded'],
            'skipped' => \count($validated['skipped']),
            'permanent_failures' => \count($allPermanent),
            'temporary_failures' => \count($apiOutcome['temporaryFailures']),
        ]);

        return new PriceUpdateResult(
            total: $total,
            succeeded: $apiOutcome['succeeded'],
            skipped: $validated['skipped'],
            permanentFailures: $allPermanent,
            temporaryFailures: $apiOutcome['temporaryFailures'],
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Validation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Validate commands: ownership, unchanged, price relationships.
     *
     * @param list<UpdatePriceCommand> $skuUpdates
     * @param array<string, ProductRetailPricing> $currentPrices
     *
     * @return array{
     *     commands: list<UpdatePriceCommand>,
     *     commandsBySku: array<string, UpdatePriceCommand>,
     *     skipped: list<array{sku: string}>,
     *     permanentFailures: list<array{sku: string, error: string}>,
     * }
     */
    private function validateCommands(
        array $skuUpdates,
        Product $product,
        array $currentPrices,
        IntId $productId,
    ): array {
        /** @var list<array{sku: string}> $skipped */
        $skipped = [];
        /** @var list<array{sku: string, error: string}> $permanentFailures */
        $permanentFailures = [];
        /** @var list<UpdatePriceCommand> $commands */
        $commands = [];
        /** @var array<string, UpdatePriceCommand> $commandsBySku */
        $commandsBySku = [];

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

            // Build effective pricing (carry-forward: command field ?? current field)
            $effectivePricing = self::buildEffectivePricing($command, $currentPricing);

            // 2. Skip unchanged prices (soft: failed → skip)
            $changeResult = (new PriceChangedValidator(
                proposed: $effectivePricing,
                current: $currentPricing,
            ))->validate();

            if ($changeResult->failed()) {
                $skipped[] = ['sku' => $skuValue];

                continue;
            }

            // 3. Validate price relationships (soft: failed → permanent failure)
            $pricingResult = (new HasValidRetailPricingValidator(
                pricing: $effectivePricing,
            ))->validate();

            if ($pricingResult->failed()) {
                $permanentFailures[] = ['sku' => $skuValue, 'error' => $pricingResult->reason()];

                continue;
            }

            $commands[] = $command;
            $commandsBySku[$skuValue] = $command;
        }

        return [
            'commands' => $commands,
            'commandsBySku' => $commandsBySku,
            'skipped' => $skipped,
            'permanentFailures' => $permanentFailures,
        ];
    }

    /**
     * Build effective ProductRetailPricing from command + current (carry-forward).
     *
     * Command field takes precedence; null fields carry forward from current pricing.
     * A zero sale price is converted to null (clearing the sale).
     */
    private static function buildEffectivePricing(
        UpdatePriceCommand $command,
        ProductRetailPricing $current,
    ): ProductRetailPricing {
        $effectiveBase = $command->price ?? $current->basePrice;

        $effectiveSale = $command->salePrice !== null
            ? ($command->salePrice->isZero() ? null : $command->salePrice)
            : $current->salePrice;

        return new ProductRetailPricing(
            basePrice: $effectiveBase,
            salePrice: $effectiveSale,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // API Communication
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Send validated commands to the API and classify results.
     *
     * @param list<UpdatePriceCommand> $commands
     *
     * @return array{
     *     succeeded: int,
     *     updatedSkus: list<Sku>,
     *     permanentFailures: list<array{sku: string, error: string}>,
     *     temporaryFailures: list<array{sku: string, error: string}>,
     * }
     *
     * @throws InvalidApiRequestException When all chunks fail (programming error)
     * @throws AuthenticationExpiredException When all chunks fail (auth)
     * @throws ExternalServiceUnavailableException When all chunks fail (transient)
     * @throws InvalidApiResponseException When all chunks fail (contract violation)
     */
    private function sendToApi(array $commands): array
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

        return [
            'succeeded' => $succeeded,
            'updatedSkus' => $updatedSkus,
            'permanentFailures' => $permanentFailures,
            'temporaryFailures' => $temporaryFailures,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pricing Map
    // ─────────────────────────────────────────────────────────────────────────

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

    // ─────────────────────────────────────────────────────────────────────────
    // Events
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Dispatch per-SKU and per-product events for confirmed updates.
     *
     * @param list<Sku> $updatedSkus
     * @param array<string, ProductRetailPricing> $currentPrices
     * @param array<string, UpdatePriceCommand> $commandsBySku
     */
    private function dispatchEvents(
        IntId $productId,
        array $updatedSkus,
        array $currentPrices,
        array $commandsBySku,
    ): void {
        foreach ($updatedSkus as $sku) {
            $previous = $currentPrices[$sku->value] ?? null;
            $command = $commandsBySku[$sku->value] ?? null;
            Assert::notNull($previous, "Updated SKU {$sku->value} must have pricing data");
            Assert::notNull($command, "Updated SKU {$sku->value} must have a command");

            $newPrices = new ProductRetailPricing(
                basePrice: $command->price ?? $previous->basePrice,
                salePrice: $command->salePrice !== null
                    ? ($command->salePrice->isZero() ? null : $command->salePrice)
                    : $previous->salePrice,
            );

            $this->events->dispatch(new SkuRetailPricingUpdatedEvent(
                sku: $sku,
                previousPrices: $previous,
                newPrices: $newPrices,
            ));
        }

        $this->events->dispatch(new ProductPricingUpdatedEvent(
            productId: $productId,
            updatedSkus: $updatedSkus,
        ));
    }
}
