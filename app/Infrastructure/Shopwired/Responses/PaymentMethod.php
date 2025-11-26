<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use App\Domain\Order\ValueObjects\PaymentMethod as DomainPaymentMethod;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * ShopWired API Response: Payment Method Data.
 *
 * Infrastructure DTO for parsing API responses only.
 * Handles snake_case mapping and required field checks.
 *
 * Fields match actual ShopWired API response structure.
 *
 * @see https://shopwired.readme.io/docs/payment-methods
 */
#[MapInputName(SnakeCaseMapper::class)]
final class PaymentMethod extends Data
{
    public function __construct(
        #[Required]
        public readonly int $id,
        #[Required]
        public readonly string $name,
    ) {}

    /**
     * Convert to Domain Value Object.
     *
     * Maps this Infrastructure DTO to the Domain PaymentMethod VO.
     * ID is not included in domain (external system concern).
     */
    public function toDomainPaymentMethod(): DomainPaymentMethod
    {
        return new DomainPaymentMethod(
            name: $this->name,
        );
    }
}
