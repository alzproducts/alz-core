<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs;

use App\Domain\Catalog\Product\Commands\SetFreeDeliveryCommand;
use App\Domain\Catalog\Product\Enums\FreeDeliveryType;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Data;
use ValueError;

/**
 * Per-item DTO for bulk free delivery update.
 *
 * Accepts: {"identifier": "SKU123", "type": "Standard"}
 * or:      {"identifier": 5585518, "type": "Express"}
 */
final class FreeDeliveryUpdateItemDTO extends Data
{
    public function __construct(
        public readonly int|string $identifier,
        public readonly string $type,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'identifier' => ['required'],
            'type' => ['required', 'string', Rule::enum(FreeDeliveryType::class)],
        ];
    }

    /**
     * @throws ValueError When the type is invalid
     */
    public function toCommand(): SetFreeDeliveryCommand
    {
        return new SetFreeDeliveryCommand(
            $this->identifier,
            FreeDeliveryType::fromString($this->type),
        );
    }
}
