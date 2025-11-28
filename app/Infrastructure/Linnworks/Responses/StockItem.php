<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Responses;

use App\Domain\Exceptions\InvalidApiResponseException;
use App\Domain\Inventory\ValueObjects\StockItem as DomainStockItem;
use App\Infrastructure\Contracts\DomainConvertible;
use App\Infrastructure\Linnworks\Support\PascalCaseMapper;
use DateMalformedStringException;
use DateTimeImmutable;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Linnworks stock item API response DTO.
 *
 * Maps all Linnworks-specific fields from their PascalCase API format.
 * Converts to vendor-agnostic Domain StockItem via toDomain().
 *
 * @template-pattern Infrastructure Response DTO
 */
#[MapInputName(PascalCaseMapper::class)]
final class StockItem extends Data implements DomainConvertible
{
    public function __construct(
        public readonly string $stockItemId,
        public readonly int $stockItemIntId,
        public readonly string $itemNumber,
        public readonly string $itemTitle,
        public readonly string $itemDescription,
        public readonly string $barcodeNumber,
        public readonly int $quantity,
        public readonly int $inOrder,
        public readonly int $due,
        public readonly int $available,
        public readonly int $minimumLevel,
        public readonly float $purchasePrice,
        public readonly float $retailPrice,
        public readonly float $taxRate,
        public readonly ?float $weight,
        public readonly float $height,
        public readonly float $width,
        public readonly float $depth,
        public readonly string $categoryId,
        public readonly string $categoryName,
        public readonly ?string $creationDate,
        public readonly ?bool $isCompositeParent,
        public readonly bool $isBatchedStockType,
        public readonly int $inventoryTrackingType,
    ) {}

    /**
     * @throws InvalidApiResponseException When date format is invalid
     */
    public function toDomain(): DomainStockItem
    {
        return new DomainStockItem(
            sku: $this->itemNumber,
            title: $this->itemTitle,
            description: $this->itemDescription,
            barcode: $this->barcodeNumber,
            quantity: $this->quantity,
            available: $this->available,
            inOrder: $this->inOrder,
            due: $this->due,
            minimumLevel: $this->minimumLevel,
            purchasePrice: $this->purchasePrice,
            retailPrice: $this->retailPrice,
            taxRate: $this->taxRate,
            weight: $this->weight,
            height: $this->height,
            width: $this->width,
            depth: $this->depth,
            categoryName: $this->categoryName,
            createdAt: $this->parseCreationDate(),
            isComposite: $this->isCompositeParent ?? false,
        );
    }

    /**
     * Parse creation date with proper exception handling.
     *
     * @throws InvalidApiResponseException When date format is invalid
     */
    private function parseCreationDate(): ?DateTimeImmutable
    {
        if ($this->creationDate === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($this->creationDate);
        } catch (DateMalformedStringException $e) {
            throw new InvalidApiResponseException(
                'Linnworks',
                "Invalid date format for creationDate: {$this->creationDate}",
                $e,
            );
        }
    }
}
