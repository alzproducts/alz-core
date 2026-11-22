<?php

declare(strict_types=1);

namespace App\Domain\QuickBooks\Exceptions;

use App\Domain\Exceptions\Api\PermanentApiFailure;
use App\Domain\QuickBooks\ValueObjects\QuickBooksFault;
use Override;

/**
 * QuickBooks rejected a call with a Fault that retrying will not fix.
 */
final class QuickBooksFaultException extends PermanentApiFailure
{
    public function __construct(
        string $serviceName,
        public readonly QuickBooksFault $fault,
    ) {
        parent::__construct($serviceName, 'QuickBooks rejected the request');
    }

    /**
     * intentional: the Fault's message stays out, since its Detail can hold customer names.
     */
    #[Override]
    public function context(): array
    {
        return [
            ...parent::context(),
            'kind' => $this->fault->kind->value,
            'code' => $this->fault->code,
            'element' => $this->fault->element,
        ];
    }
}
