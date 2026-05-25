<?php

declare(strict_types=1);

namespace App\Application\Contracts\Conversion\CallTracking;

use App\Domain\Conversion\CallTracking\ValueObjects\PhoneNumberE164;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;

interface CallTrackingNumberRepositoryInterface
{
    /**
     * Ordered by `sort_order` so round-robin assignment is deterministic.
     *
     * @return list<PhoneNumberE164>
     *
     * @throws DatabaseOperationFailedException
     * @throws ExternalServiceUnavailableException
     */
    public function findAllActive(): array;

    /**
     * Atomic — implementations must use a single statement (e.g. `UPDATE ... RETURNING`)
     * so concurrent callers cannot read the same counter value.
     *
     * @throws DatabaseOperationFailedException
     * @throws ExternalServiceUnavailableException
     */
    public function incrementAndGetCounter(): int;
}
