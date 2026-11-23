<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\Mappings\DTOs;

use App\Application\QuickBooks\OrderSync\Enums\MappingKey;
use App\Domain\QuickBooks\OrderSync\Enums\FixedRefKey;
use App\Domain\QuickBooks\OrderSync\Enums\PaymentKey;
use App\Domain\QuickBooks\OrderSync\Enums\TaxKey;

/**
 * An unresolved key reads as null.
 */
final readonly class ResolvedMappingRefsDTO
{
    /**
     * @param array<string, non-empty-string> $refIds keyed by MappingKey value
     */
    public function __construct(private array $refIds) {}

    /**
     * @return non-empty-string|null
     */
    public function tax(TaxKey $key): ?string
    {
        return $this->refId(MappingKey::forTax($key));
    }

    /**
     * @return non-empty-string|null
     */
    public function fixedRef(FixedRefKey $key): ?string
    {
        return $this->refId(MappingKey::forFixedRef($key));
    }

    /**
     * @return non-empty-string|null
     */
    public function depositAccount(PaymentKey $key): ?string
    {
        return $this->refId(MappingKey::forDepositAccount($key));
    }

    /**
     * @return non-empty-string|null
     */
    public function paymentMethod(PaymentKey $key): ?string
    {
        return $this->refId(MappingKey::forPaymentMethod($key));
    }

    /**
     * @return non-empty-string|null
     */
    public function poNumberField(): ?string
    {
        return $this->refId(MappingKey::PoNumberField);
    }

    /**
     * @return non-empty-string|null
     */
    public function transactionIdField(): ?string
    {
        return $this->refId(MappingKey::TransactionIdField);
    }

    /**
     * @return non-empty-string|null
     */
    private function refId(MappingKey $key): ?string
    {
        return $this->refIds[$key->value] ?? null;
    }
}
