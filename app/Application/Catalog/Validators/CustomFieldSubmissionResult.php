<?php

declare(strict_types=1);

namespace App\Application\Catalog\Validators;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Shared\Validation\Concerns\ThrowsOnValidationFailureTrait;
use App\Domain\Shared\Validation\Contracts\DescribableValidationResultInterface;

/**
 * Result of custom field submission validation.
 *
 * Named constructors for each outcome:
 * - valid() — validation succeeded
 * - unknownField() — field not in registry
 * - invalidValue() — type mismatch
 */
final readonly class CustomFieldSubmissionResult implements DescribableValidationResultInterface
{
    use ThrowsOnValidationFailureTrait;

    private function __construct(
        private bool $isValid,
        private string $failureReason,
        /** @var array<string, mixed> */
        private array $failureContext,
    ) {}

    public static function valid(): self
    {
        return new self(isValid: true, failureReason: '', failureContext: []);
    }

    public static function unknownField(string $fieldName, CustomFieldItemType $itemType): self
    {
        return new self(
            isValid: false,
            failureReason: "Unknown custom field '{$fieldName}' for item type '{$itemType->value}'",
            failureContext: [
                'custom_fields' => ["The field '{$fieldName}' is not a recognised custom field."],
            ],
        );
    }

    public static function invalidValue(string $fieldName, CustomFieldType $expectedType, string $actualType): self
    {
        return new self(
            isValid: false,
            failureReason: "Custom field '{$fieldName}' expected type '{$expectedType->value}' but received '{$actualType}'",
            failureContext: [
                'custom_fields' => ["The field '{$fieldName}' must be of type '{$expectedType->value}', received '{$actualType}'."],
            ],
        );
    }

    public function passed(): bool
    {
        return $this->isValid;
    }

    public function failed(): bool
    {
        return ! $this->isValid;
    }

    public function reason(): string
    {
        return $this->failureReason;
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->failureContext;
    }
}
