<?php

declare(strict_types=1);

namespace App\Domain\QuickBooks\Exceptions;

use App\Domain\Exceptions\DomainException;
use Override;
use Throwable;

final class QuickBooksMappingsUnresolvedException extends DomainException
{
    /**
     * @param list<string> $missingKeys
     */
    public function __construct(
        public readonly array $missingKeys,
        ?Throwable $previous = null,
    ) {
        parent::__construct('QuickBooks mapping keys are unresolved', 0, $previous);
    }

    #[Override]
    public function context(): array
    {
        return ['missing_keys' => $this->missingKeys];
    }
}
