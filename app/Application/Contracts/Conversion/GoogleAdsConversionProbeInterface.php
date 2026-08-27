<?php

declare(strict_types=1);

namespace App\Application\Contracts\Conversion;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;

/**
 * Sends a deliberately invalid, validate-only conversion upload so operators can
 * grade Google's structured rejection and prove the upload path still works.
 *
 * Separate from the production conversion contracts on purpose: this path must
 * carry an unparseable gclid, so it bypasses the `Gclid` value object entirely.
 */
interface GoogleAdsConversionProbeInterface
{
    /**
     * Fabricated gclid the probe sends. Declared here so `--dry-run` can print it
     * without resolving the implementation, which would build the whole SDK client chain.
     */
    public const string PROBE_GCLID = 'probe-invalid-gclid-cor-224';

    /**
     * A successful probe THROWS: Google rejects the fabricated gclid and the
     * rejection detail is the evidence the round-trip worked.
     *
     * @throws InvalidApiRequestException When Google rejects the conversion payload (partial failure)
     * @throws AuthenticationExpiredException When credentials are invalid or the account is not allowlisted
     * @throws ExternalServiceUnavailableException When the API is unavailable, rate limited, or rejects the request outright
     */
    public function probeUpload(): void;
}
