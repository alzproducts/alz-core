<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Conversion\Services;

use App\Application\Contracts\Conversion\AdPlatformConversionAdapterInterface;
use App\Application\Conversion\ConversionUploadDTO;
use App\Application\Conversion\Enums\AdPlatform;
use App\Application\Conversion\Services\AdPlatformAdapterResolverService;
use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\Conversion\Enums\ConversionType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for the adapter resolver's three lookups, using fake in-memory
 * adapters (Google supports every conversion type; Bing supports LeadReceived
 * only). Eligibility is the intersection of "adapter supports the type" and
 * "the attribution carries that adapter's click ID".
 */
#[CoversClass(AdPlatformAdapterResolverService::class)]
final class AdPlatformAdapterResolverServiceTest extends TestCase
{
    private const string GCLID = 'CjwKCAjwTestGclid12345';

    private const string MSCLKID = 'cdd4afcccb1c9a4cad9544dd7e5006d5-1';

    #[Test]
    public function eligible_platforms_returns_both_for_lead_when_both_click_ids_present(): void
    {
        $resolver = new AdPlatformAdapterResolverService([$this->makeGoogleAdapter(), $this->makeBingAdapter()]);

        self::assertSame(
            [AdPlatform::Google, AdPlatform::Bing],
            $resolver->eligiblePlatforms(ConversionType::LeadReceived, $this->attribution(self::GCLID, self::MSCLKID)),
        );
    }

    #[Test]
    public function eligible_platforms_excludes_bing_for_quote_because_it_is_unsupported(): void
    {
        $resolver = new AdPlatformAdapterResolverService([$this->makeGoogleAdapter(), $this->makeBingAdapter()]);

        self::assertSame(
            [AdPlatform::Google],
            $resolver->eligiblePlatforms(ConversionType::QuoteIssued, $this->attribution(self::GCLID, self::MSCLKID)),
        );
    }

    #[Test]
    public function eligible_platforms_returns_only_google_when_only_gclid_present(): void
    {
        $resolver = new AdPlatformAdapterResolverService([$this->makeGoogleAdapter(), $this->makeBingAdapter()]);

        self::assertSame(
            [AdPlatform::Google],
            $resolver->eligiblePlatforms(ConversionType::LeadReceived, $this->attribution(self::GCLID, null)),
        );
    }

    #[Test]
    public function eligible_platforms_returns_only_bing_when_only_msclkid_present(): void
    {
        $resolver = new AdPlatformAdapterResolverService([$this->makeGoogleAdapter(), $this->makeBingAdapter()]);

        self::assertSame(
            [AdPlatform::Bing],
            $resolver->eligiblePlatforms(ConversionType::LeadReceived, $this->attribution(null, self::MSCLKID)),
        );
    }

    #[Test]
    public function platforms_with_click_id_ignores_conversion_type_support(): void
    {
        $resolver = new AdPlatformAdapterResolverService([$this->makeGoogleAdapter(), $this->makeBingAdapter()]);

        self::assertSame(
            [AdPlatform::Bing],
            $resolver->platformsWithClickId($this->attribution(null, self::MSCLKID)),
        );
        self::assertSame(
            [AdPlatform::Google, AdPlatform::Bing],
            $resolver->platformsWithClickId($this->attribution(self::GCLID, self::MSCLKID)),
        );
    }

    #[Test]
    public function platforms_with_click_id_is_empty_when_no_click_id_present(): void
    {
        $resolver = new AdPlatformAdapterResolverService([$this->makeGoogleAdapter(), $this->makeBingAdapter()]);

        self::assertSame([], $resolver->platformsWithClickId($this->attribution(null, null)));
    }

    #[Test]
    public function adapter_for_returns_the_registered_adapter_for_the_platform(): void
    {
        $google = $this->makeGoogleAdapter();
        $bing = $this->makeBingAdapter();
        $resolver = new AdPlatformAdapterResolverService([$google, $bing]);

        self::assertSame($google, $resolver->adapterFor(AdPlatform::Google));
        self::assertSame($bing, $resolver->adapterFor(AdPlatform::Bing));
    }

    #[Test]
    public function adapter_for_throws_when_no_adapter_is_registered_for_the_platform(): void
    {
        $resolver = new AdPlatformAdapterResolverService([$this->makeGoogleAdapter()]);

        $this->expectException(InvalidArgumentException::class);

        $resolver->adapterFor(AdPlatform::Bing);
    }

    private function attribution(?string $gclid, ?string $msclkid): MarketingAttribution
    {
        return new MarketingAttribution(gclid: $gclid, msclkid: $msclkid);
    }

    private function makeGoogleAdapter(): AdPlatformConversionAdapterInterface
    {
        return new class implements AdPlatformConversionAdapterInterface {
            public function platform(): AdPlatform
            {
                return AdPlatform::Google;
            }

            public function supports(ConversionType $type): bool
            {
                return true;
            }

            public function extractClickId(MarketingAttribution $attribution): ?string
            {
                return $attribution->gclid;
            }

            public function upload(ConversionType $type, ConversionUploadDTO $data): void {}
        };
    }

    private function makeBingAdapter(): AdPlatformConversionAdapterInterface
    {
        return new class implements AdPlatformConversionAdapterInterface {
            public function platform(): AdPlatform
            {
                return AdPlatform::Bing;
            }

            public function supports(ConversionType $type): bool
            {
                return $type === ConversionType::LeadReceived;
            }

            public function extractClickId(MarketingAttribution $attribution): ?string
            {
                return $attribution->msclkid;
            }

            public function upload(ConversionType $type, ConversionUploadDTO $data): void {}
        };
    }
}
