<?php

declare(strict_types=1);

namespace App\Presentation\Http\Checkout\DTOs;

use App\Application\Checkout\DTOs\VatReliefDeclarationDTO;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Wire-format DTO for the vat_relief sub-object.
 *
 * Spatie LaravelData lives in Presentation only; the Application command holds
 * a framework-free {@see VatReliefDeclarationDTO}. Conversion happens in
 * {@see toDeclaration()} (called by the presentation mapper).
 */
#[MapInputName(SnakeCaseMapper::class)]
final class VatReliefRequestDTO extends Data
{
    public function __construct(
        public readonly ?bool $eligible = null,
        public readonly ?string $name = null,
        public readonly ?string $address = null,
        public readonly ?string $condition = null,
        public readonly ?string $signedAt = null,
    ) {}

    public function toDeclaration(): VatReliefDeclarationDTO
    {
        return new VatReliefDeclarationDTO(
            eligible: $this->eligible,
            name: $this->name,
            address: $this->address,
            condition: $this->condition,
            signedAt: $this->signedAt,
        );
    }
}
