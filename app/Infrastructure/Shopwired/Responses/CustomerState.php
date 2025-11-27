<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use App\Domain\Customer\ValueObjects\State;
use App\Infrastructure\Contracts\DomainConvertible;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * ShopWired API Response: Customer State (embedded).
 *
 * Infrastructure DTO for parsing state/province data from customer responses.
 *
 * @see https://shopwired.readme.io/reference/listcustomers
 */
#[MapInputName(SnakeCaseMapper::class)]
final class CustomerState extends Data implements DomainConvertible
{
    public function __construct(
        public readonly string $name,
    ) {}

    /**
     * Convert to Domain Value Object.
     */
    public function toDomain(): State
    {
        return new State(name: $this->name);
    }
}
