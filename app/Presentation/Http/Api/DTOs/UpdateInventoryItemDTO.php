<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs;

use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Data\InvalidSkuException;
use App\Domain\Inventory\Commands\UpdateInventoryFieldsCommand;
use App\Domain\Inventory\ValueObjects\InventoryFieldUpdate;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;

/**
 * NOTE — JIT is intentionally not exposed here even though the underlying
 * domain (`InventoryFieldUpdate::jit()`), client mapping, and repository
 * column all support it. The current Linnworks subscription does not include
 * the JIT feature — every JIT write returns 400 "Subscription does not have
 * required feature to update JIT." Re-add a `bool|Optional $jit` property
 * (and the matching `toCommand()` branch) once the subscription is upgraded.
 */
final class UpdateInventoryItemDTO extends Data
{
    public function __construct(
        public readonly string $sku,
        #[Required, IntegerType, Min(0)]
        public readonly int $minimum_level,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'min:1'],
        ];
    }

    /**
     * @throws InvalidSkuException When the SKU format is invalid
     */
    public function toCommand(): UpdateInventoryFieldsCommand
    {
        return new UpdateInventoryFieldsCommand(
            sku: Sku::fromString($this->sku),
            updates: [InventoryFieldUpdate::minimumLevel($this->minimum_level)],
        );
    }
}
