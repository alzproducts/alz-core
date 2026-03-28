<?php

declare(strict_types=1);

namespace App\Domain\Exceptions\Inventory;

use App\Domain\Exceptions\DomainException;

/**
 * Thrown when a template stock item doesn't meet requirements for an operation.
 *
 * Used when copying from a template item but the template lacks required data
 * (e.g., no default supplier, missing category, etc.).
 */
final class InvalidTemplateException extends DomainException
{
    public function __construct(
        public readonly string $templateSku,
        public readonly string $reason,
    ) {
        parent::__construct('Invalid template stock item');
    }

    public static function noDefaultSupplier(string $templateSku): self
    {
        return new self($templateSku, 'no default supplier configured');
    }

    public function context(): array
    {
        return [
            'template_sku' => $this->templateSku,
            'reason' => $this->reason,
        ];
    }
}
