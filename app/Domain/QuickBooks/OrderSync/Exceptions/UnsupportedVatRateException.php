<?php

declare(strict_types=1);

namespace App\Domain\QuickBooks\OrderSync\Exceptions;

use App\Domain\Exceptions\DomainException;

/**
 * A goods or shipping VAT rate that is neither 0 nor 20, or is missing: the order has no tax code to post.
 */
final class UnsupportedVatRateException extends DomainException
{
    public function __construct(
        public readonly string $orderReference,
        public readonly ?float $rate,
    ) {
        parent::__construct('VAT rate is not supported by the QuickBooks order sync');
    }

    /**
     * @return array{order_reference: string, rate: float|null}
     */
    public function context(): array
    {
        return [
            'order_reference' => $this->orderReference,
            'rate' => $this->rate,
        ];
    }
}
