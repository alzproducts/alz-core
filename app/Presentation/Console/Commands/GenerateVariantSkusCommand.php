<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Application\Inventory\Commands\GenerateVariantSkusCommand as UseCaseCommand;
use App\Application\Inventory\Results\GenerateVariantSkusResult;
use App\Application\Inventory\UseCases\GenerateVariantSkusUseCase;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Data\InvalidSkuException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use App\Domain\Exceptions\Inventory\InvalidTemplateException;
use App\Domain\ValueObjects\IntId;
use Illuminate\Console\Command;

/**
 * Generate Linnworks inventory items for SKU-less ShopWired variations.
 *
 * For each variation without a SKU:
 * 1. Generates a new SKU in Linnworks
 * 2. Creates inventory item with category/supplier from template
 * 3. Links supplier and adds extended properties
 * 4. Updates ShopWired variation with the new SKU
 *
 * Uses distributed locking to prevent SKU race conditions.
 *
 * Examples:
 *   php artisan inventory:generate-variant-skus 5585518 WEB-15424
 *   railway ssh -s alz-core-worker 'php artisan inventory:generate-variant-skus 5585518 WEB-15424'
 */
final class GenerateVariantSkusCommand extends Command
{
    protected $signature = 'inventory:generate-variant-skus
                            {productId : ShopWired product ID}
                            {templateSku : Linnworks SKU to use as template for category/supplier}
                            {--copy-mpn : Use template\'s default supplier code as MPN for all variants}
                            {--no-supplier : Skip supplier linking in Linnworks (still uses template for category)}
                            {--is-standard-sign : Match purchase prices against standard sign product variants}';

    protected $description = 'Generate Linnworks inventory items for SKU-less ShopWired product variations';

    public function handle(GenerateVariantSkusUseCase $useCase): int
    {
        $productId = $this->parseProductId($this->argument('productId'));
        if ($productId === null) {
            return self::FAILURE;
        }

        $templateSku = $this->parseTemplateSku($this->argument('templateSku'));
        if ($templateSku === null) {
            return self::FAILURE;
        }

        $copyMpn = $this->option('copy-mpn');
        $noSupplier = $this->option('no-supplier');
        $isStandardSign = $this->option('is-standard-sign');

        $this->info("Generating variant SKUs for product {$productId->value}");
        $this->line("  Template: {$templateSku->value}");

        if ($copyMpn) {
            $this->line('  MPN Source: template supplier code (--copy-mpn)');
        }

        if ($noSupplier) {
            $this->line('  Supplier: skipped (--no-supplier)');
        }

        if ($isStandardSign) {
            $this->line('  Pricing: standard sign matching (--is-standard-sign)');
        }

        $this->newLine();

        try {
            $result = $useCase->execute(new UseCaseCommand(
                $productId,
                $templateSku,
                copyParentMpn: $copyMpn,
                noSupplier: $noSupplier,
                isStandardSign: $isStandardSign,
            ));

            return $this->displaySuccessResult($result);
        } catch (ResourceNotFoundException|InvalidTemplateException|LockAcquisitionException|AuthenticationExpiredException|ExternalServiceUnavailableException|InvalidApiRequestException|InvalidApiResponseException|DatabaseOperationFailedException|DuplicateRecordException $e) {
            return $this->handleExecutionError($e);
        }
    }

    /**
     * Display success result and return appropriate exit code.
     */
    private function displaySuccessResult(GenerateVariantSkusResult $result): int
    {
        $this->table(
            ['Total Variations', 'Already Had SKU', 'Created', 'Failed'],
            [[$result->total, $result->skipped, $result->created, $result->failed]],
        );

        if ($result->total === 0) {
            $this->warn('Product has no variations.');
        } elseif ($result->created === 0 && $result->skipped > 0) {
            $this->info('All variations already have SKUs - nothing to create.');
        }

        if ($result->createdSkus !== []) {
            $this->newLine();
            $this->info('Created SKUs:');
            foreach ($result->createdSkus as $sku) {
                $this->line("  - {$sku}");
            }
        }

        if ($result->failedVariationIds !== []) {
            $this->newLine();
            $this->warn('Failed variation IDs (see logs for details):');
            foreach ($result->failedVariationIds as $variationId) {
                $this->line("  - {$variationId}");
            }
        }

        return $result->failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Handle UseCase execution errors with user-friendly messages.
     */
    private function handleExecutionError(
        ResourceNotFoundException|InvalidTemplateException|LockAcquisitionException|AuthenticationExpiredException|ExternalServiceUnavailableException|InvalidApiRequestException|InvalidApiResponseException|DatabaseOperationFailedException|DuplicateRecordException $e,
    ): int {
        [$errorMsg, $hintMsg] = match (true) {
            $e instanceof ResourceNotFoundException => [
                "Resource not found: {$e->getMessage()}",
                'Check that the product ID and template SKU exist',
            ],
            $e instanceof InvalidTemplateException => [
                "Invalid template: {$e->getMessage()}",
                "Template SKU '{$e->templateSku}' must have a default supplier configured",
            ],
            $e instanceof LockAcquisitionException => [
                'Could not acquire SKU generation lock',
                'Another process may be generating SKUs. Wait and retry.',
            ],
            $e instanceof AuthenticationExpiredException => [
                "Authentication expired: {$e->serviceName}",
                'Re-authenticate and retry',
            ],
            $e instanceof ExternalServiceUnavailableException => [
                "Service unavailable: {$e->serviceName}",
                $e->retryAfter !== null ? "Retry in {$e->retryAfter}s" : 'Retry later',
            ],
            $e instanceof InvalidApiRequestException, $e instanceof InvalidApiResponseException => [
                "API error: {$e->getMessage()}",
                'Check logs for details',
            ],
            $e instanceof DatabaseOperationFailedException, $e instanceof DuplicateRecordException => [
                "Database error during local refresh: {$e->getMessage()}",
                'SKUs may have been created - check Linnworks',
            ],
        };

        $this->error($errorMsg);
        $this->warn("  {$hintMsg}");

        return self::FAILURE;
    }

    /**
     * Parse and validate product ID argument.
     */
    private function parseProductId(mixed $value): ?IntId
    {
        if (!\is_string($value) || !\ctype_digit($value)) {
            $this->error('Product ID must be a positive integer');

            return null;
        }

        $intValue = (int) $value;

        if ($intValue <= 0) {
            $this->error('Product ID must be greater than 0');

            return null;
        }

        return IntId::from($intValue);
    }

    /**
     * Parse and validate template SKU argument.
     */
    private function parseTemplateSku(mixed $value): ?Sku
    {
        if (!\is_string($value)) {
            $this->error('Template SKU must be a string');

            return null;
        }

        try {
            return Sku::fromString($value);
        } catch (InvalidSkuException $e) {
            $this->error("Invalid template SKU: {$e->getMessage()}");

            return null;
        }
    }
}
