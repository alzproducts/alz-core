<?php

declare(strict_types=1);

namespace App\Domain\Inventory\ValueObjects;

use App\Domain\Inventory\Enums\ExtendedPropertyName;
use Webmozart\Assert\Assert;

/**
 * Write-side representation of an extended property.
 *
 * Used when setting EPs on a stock item — carries only the name (enum)
 * and value. Contrast with StockItemExtendedProperty (read model) which
 * also carries rowId and type from the API response.
 *
 * @template-pattern Domain Value Object
 */
final readonly class ExtendedPropertyWrite
{
    private function __construct(
        public ExtendedPropertyName $name,
        public string $value,
    ) {}

    /**
     * Create from a typed ExtendedPropertyName enum.
     */
    public static function create(ExtendedPropertyName $name, string $value): self
    {
        return new self($name, $value);
    }

    /**
     * Create from a raw property name string.
     *
     * Validates the name against known ExtendedPropertyName values.
     * Throws on invalid names — this is an internal contract, not external input.
     */
    public static function fromString(string $name, string $value): self
    {
        $epName = ExtendedPropertyName::tryFrom($name);
        Assert::notNull($epName, "Unknown extended property name '{$name}'");

        return new self($epName, $value);
    }
}
