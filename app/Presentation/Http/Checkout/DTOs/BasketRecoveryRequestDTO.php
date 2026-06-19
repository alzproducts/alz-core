<?php

declare(strict_types=1);

namespace App\Presentation\Http\Checkout\DTOs;

use LogicException;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapInputName(SnakeCaseMapper::class)]
final class BasketRecoveryRequestDTO extends Data
{
    public function __construct(
        #[IntegerType, Min(1), Max(30)]
        public readonly int $scopeWindow = 4,
        #[Nullable, StringType]
        public readonly ?string $onlyNeedsUpdate = null,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'only_needs_update' => ['nullable', 'string', 'in:true,false,1,0'],
        ];
    }

    public function resolveOnlyNeedsUpdate(): bool
    {
        return self::parseBoolFilter($this->onlyNeedsUpdate) ?? true;
    }

    private static function parseBoolFilter(?string $value): ?bool
    {
        return match ($value) {
            null => null,
            'true', '1' => true,
            'false', '0' => false,
            default => throw new LogicException('Bool filter passed validation but did not match rule: ' . $value),
        };
    }
}
