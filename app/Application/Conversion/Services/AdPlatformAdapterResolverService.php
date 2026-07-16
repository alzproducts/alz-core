<?php

declare(strict_types=1);

namespace App\Application\Conversion\Services;

use App\Application\Contracts\Conversion\AdPlatformConversionAdapterInterface;
use App\Application\Conversion\Enums\AdPlatform;
use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\Conversion\Enums\ConversionType;
use Webmozart\Assert\Assert;

/**
 * Resolves ad-platform adapters for the conversion fan-out: which platforms an
 * upload can actually be attempted on, and the adapter for a given platform.
 */
final readonly class AdPlatformAdapterResolverService
{
    /**
     * @param list<AdPlatformConversionAdapterInterface> $adapters
     */
    public function __construct(private array $adapters) {}

    /**
     * Platforms that both support the conversion type AND have a click ID present
     * in the attribution — the only platforms an upload can be attempted on.
     *
     * @return list<AdPlatform>
     */
    public function eligiblePlatforms(ConversionType $type, MarketingAttribution $attribution): array
    {
        $eligible = [];

        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($type) && $adapter->extractClickId($attribution) !== null) {
                $eligible[] = $adapter->platform();
            }
        }

        return $eligible;
    }

    /**
     * Platforms that have a click ID present in the attribution, regardless of
     * whether they support any given conversion type. Lets the fan-out tell
     * "no ad click at all" (a data error) apart from "click present but no
     * platform supports this conversion type" (a graceful skip).
     *
     * @return list<AdPlatform>
     */
    public function platformsWithClickId(MarketingAttribution $attribution): array
    {
        $platforms = [];

        foreach ($this->adapters as $adapter) {
            if ($adapter->extractClickId($attribution) !== null) {
                $platforms[] = $adapter->platform();
            }
        }

        return $platforms;
    }

    public function adapterFor(AdPlatform $platform): AdPlatformConversionAdapterInterface
    {
        $adapter = \array_find(
            $this->adapters,
            static fn(AdPlatformConversionAdapterInterface $candidate): bool => $candidate->platform() === $platform,
        );

        Assert::notNull($adapter, 'No conversion adapter is registered for the requested ad platform');

        return $adapter;
    }
}
