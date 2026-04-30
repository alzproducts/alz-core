<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs;

use App\Application\Inventory\Commands\GenerateVariantSkusCommand;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Data\InvalidSkuException;
use App\Domain\ValueObjects\IntId;
use Spatie\LaravelData\Attributes\Validation\BooleanType;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;

/**
 * Request validation for POST /api/products/{productId}/generate-variant-skus.
 */
final class GenerateVariantSkusRequestDTO extends Data
{
    public function __construct(
        #[Required, StringType]
        public readonly string $template_sku,
        #[BooleanType]
        public readonly bool $copy_mpn = false,
        #[BooleanType]
        public readonly bool $no_supplier = false,
        #[BooleanType]
        public readonly bool $is_standard_sign = false,
    ) {}

    /**
     * @throws InvalidSkuException When template SKU format is invalid
     */
    public function toCommand(IntId $productId): GenerateVariantSkusCommand
    {
        return new GenerateVariantSkusCommand(
            productId: $productId,
            templateSku: Sku::fromString($this->template_sku),
            copyParentMpn: $this->copy_mpn,
            noSupplier: $this->no_supplier,
            isStandardSign: $this->is_standard_sign,
        );
    }
}
