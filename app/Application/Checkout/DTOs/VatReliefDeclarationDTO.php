<?php

declare(strict_types=1);

namespace App\Application\Checkout\DTOs;

/**
 * VAT-relief declaration captured at checkout.
 *
 * Mirrors ShopWired's VAT-relief form. All fields nullable — the inbound shape
 * varies per declarant and ShopWired may add fields over time. Plain DTO (no
 * framework dependency) so it can live in the Application layer.
 */
final readonly class VatReliefDeclarationDTO
{
    public function __construct(
        public ?bool $eligible = null,
        public ?string $name = null,
        public ?string $address = null,
        public ?string $condition = null,
        public ?string $signedAt = null,
    ) {}
}
