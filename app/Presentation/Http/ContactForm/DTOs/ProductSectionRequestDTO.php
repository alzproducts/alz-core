<?php

declare(strict_types=1);

namespace App\Presentation\Http\ContactForm\DTOs;

use App\Domain\ContactSubmission\Enums\ProductSource;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Selected product from contact form submission.
 *
 * Optional section - entire product object can be omitted.
 * ProductId is required when the section is present; SKU is optional.
 * Stored as JSONB, so no length limits on string fields.
 */
#[MapInputName(SnakeCaseMapper::class)]
final class ProductSectionRequestDTO extends Data
{
    public function __construct(
        #[Required, IntegerType, Min(1)]
        public readonly int $productId,
        #[Nullable, StringType]
        public readonly ?string $sku = null,
        #[Nullable, StringType]
        public readonly ?string $title = null,
        #[Nullable, StringType]
        public readonly ?string $price = null,
        #[Nullable, StringType]
        public readonly ?string $url = null,
        #[Nullable, StringType]
        public readonly ?string $manualUrl = null,
        #[Nullable, StringType]
        public readonly ?string $source = null,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'source' => ['nullable', 'string', Rule::in(self::getProductSourceValues())],
        ];
    }

    /**
     * @return list<string>
     */
    private static function getProductSourceValues(): array
    {
        return \array_map(
            static fn(ProductSource $source): string => $source->value,
            ProductSource::cases(),
        );
    }
}
