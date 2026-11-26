<?php

declare(strict_types=1);

namespace App\Application\Contracts\Access;

interface ConnectionPeerResolverInterface
{
    /**
     * Resolve the address of the current request's TCP peer, which no request header can set.
     *
     * @param  ?string  $reportedAddress  REMOTE_ADDR as the server runtime reports it
     * @return ?string  Null when the peer cannot be established
     */
    public function resolve(?string $reportedAddress): ?string;
}
