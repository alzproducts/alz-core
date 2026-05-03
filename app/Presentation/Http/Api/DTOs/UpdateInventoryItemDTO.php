<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs;

use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Data\InvalidSkuException;
use App\Domain\Inventory\Commands\UpdateInventoryFieldsCommand;
use App\Domain\Inventory\ValueObjects\InventoryFieldUpdate;
use Spatie\LaravelData\Attributes\Validation\BooleanType;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\RequiredWithoutAll;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

final class UpdateInventoryItemDTO extends Data
{
    public function __construct(
        public readonly string $sku,
        #[RequiredWithoutAll('minimum_level'), BooleanType]
        public readonly bool|Optional $jit = new Optional(),
        #[RequiredWithoutAll('jit'), IntegerType, Min(0)]
        public readonly int|Optional $minimum_level = new Optional(),
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
        $updates = [];

        if (!($this->jit instanceof Optional)) {
            $updates[] = InventoryFieldUpdate::jit($this->jit);
        }

        if (!($this->minimum_level instanceof Optional)) {
            $updates[] = InventoryFieldUpdate::minimumLevel($this->minimum_level);
        }

        return new UpdateInventoryFieldsCommand(
            sku: Sku::fromString($this->sku),
            updates: $updates,
        );
    }
}
