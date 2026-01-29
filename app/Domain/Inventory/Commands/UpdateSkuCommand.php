<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Commands;

use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Data\InvalidSkuException;
use App\Domain\Inventory\Enums\SkuUpdateReason;
use App\Domain\Inventory\Enums\SkuUpdateType;
use Webmozart\Assert\Assert;

/**
 * Command to update a SKU across platforms.
 *
 * Uses business identifiers (SKUs) only - no external system IDs.
 * When type=Provided, newSku must be supplied.
 * When type=Generated, system will auto-generate the new SKU.
 */
final readonly class UpdateSkuCommand
{
    /**
     * @param string $oldSku Current SKU to find and update
     * @param Sku|null $newSku New SKU value (required when type=Provided)
     * @param SkuUpdateType $type How the new SKU is determined
     * @param SkuUpdateReason $reason Business reason for the change
     */
    public function __construct(
        public string $oldSku,
        public ?Sku $newSku,
        public SkuUpdateType $type,
        public SkuUpdateReason $reason,
    ) {
        Assert::notEmpty(\mb_trim($oldSku), 'oldSku cannot be empty');

        if ($type === SkuUpdateType::Provided) {
            Assert::notNull($newSku, 'newSku is required when type is Provided');
        }
    }

    /**
     * Create command for a user-provided SKU value.
     */
    public static function provided(
        string $oldSku,
        Sku $newSku,
        SkuUpdateReason $reason,
    ): self {
        return new self($oldSku, $newSku, SkuUpdateType::Provided, $reason);
    }

    /**
     * Create command for auto-generated SKU.
     */
    public static function generated(
        string $oldSku,
        SkuUpdateReason $reason,
    ): self {
        return new self($oldSku, null, SkuUpdateType::Generated, $reason);
    }

    /**
     * Get the provided SKU value, validating the command state.
     *
     * @throws InvalidSkuException When newSku is null (invalid for Provided type)
     */
    public function getProvidedSku(): Sku
    {
        if ($this->newSku === null) {
            throw InvalidSkuException::missingForProvidedType();
        }

        return $this->newSku;
    }
}
