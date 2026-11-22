<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Results;

use App\Application\QuickBooks\OrderSync\DTOs\CustomerResolutionDTO;

/**
 * One resolution per distinct display name, matched ignoring case as QBO compares names.
 */
final readonly class CustomerResolutionResult
{
    /**
     * @param array<array-key, CustomerResolutionDTO> $byFoldedName intentional: keys are only looked up (an all-digit name is an int key)
     */
    private function __construct(
        private array $byFoldedName,
    ) {}

    /**
     * @param list<CustomerResolutionDTO> $resolutions the first per folded display name wins
     */
    public static function of(array $resolutions): self
    {
        $byName = [];
        foreach ($resolutions as $resolution) {
            $byName[self::fold($resolution->displayName)] ??= $resolution;
        }

        return new self($byName);
    }

    public function forDisplayName(string $displayName): ?CustomerResolutionDTO
    {
        return $this->byFoldedName[self::fold($displayName)] ?? null;
    }

    private static function fold(string $name): string
    {
        return \mb_strtolower($name);
    }
}
