<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Clients\Traits;

/**
 * Shared custom field merge logic for ShopWired update clients.
 *
 * ShopWired requires the full merged set of custom fields on every PUT.
 * Existing fields not in the update are preserved; null values clear the field.
 */
trait MergesCustomFieldsTrait
{
    /**
     * Merge new custom field values with existing.
     *
     * @param array<string, mixed> $existing Current custom field values
     * @param array<string, string|int|bool|list<string>|list<int>|null> $newFields Fields to update
     *
     * @return array<string, mixed> Merged custom fields
     */
    private static function mergeCustomFields(array $existing, array $newFields): array
    {
        $merged = $existing;

        foreach ($newFields as $name => $value) {
            $merged[$name] = $value ?? '';
        }

        return $merged;
    }
}
