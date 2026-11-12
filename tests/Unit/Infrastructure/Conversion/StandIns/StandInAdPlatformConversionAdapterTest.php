<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Conversion\StandIns;

use App\Application\Contracts\Conversion\AdPlatformConversionAdapterInterface;
use App\Application\Conversion\Enums\AdPlatform;
use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\Conversion\Enums\ConversionType;
use App\Infrastructure\BingAds\BingAdsConversionAdapter;
use App\Infrastructure\BingAds\BingAdsConversionService;
use App\Infrastructure\Conversion\StandIns\StandInAdPlatformConversionAdapter;
use App\Infrastructure\GoogleAds\GoogleAdsConversionAdapter;
use App\Infrastructure\GoogleAds\GoogleAdsConversionService;
use App\Infrastructure\Integrations\StandInCallLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The stand-in answers the eligibility questions exactly as the real adapter does, so a lead stays eligible
 * for the same platforms with credentials blank.
 */
#[CoversClass(StandInAdPlatformConversionAdapter::class)]
final class StandInAdPlatformConversionAdapterTest extends TestCase
{
    private const string GCLID = 'Cj0KCQjwexampleGclid';

    private const string MSCLKID = '0123456789abcdef0123456789abcdef';

    /**
     * @return iterable<string, array{AdPlatform, ConversionType, MarketingAttribution}>
     */
    public static function platformTypeAndAttribution(): iterable
    {
        $attributions = [
            'gclid only' => new MarketingAttribution(gclid: self::GCLID),
            'msclkid only' => new MarketingAttribution(msclkid: self::MSCLKID),
            'both' => new MarketingAttribution(gclid: self::GCLID, msclkid: self::MSCLKID),
        ];

        foreach (AdPlatform::cases() as $platform) {
            foreach (ConversionType::cases() as $type) {
                foreach ($attributions as $label => $attribution) {
                    yield "{$platform->name} {$type->name} {$label}" => [$platform, $type, $attribution];
                }
            }
        }
    }

    #[Test]
    #[DataProvider('platformTypeAndAttribution')]
    public function it_answers_as_the_real_adapter(AdPlatform $platform, ConversionType $type, MarketingAttribution $attribution): void
    {
        $real = $this->realAdapter($platform);
        $standIn = new StandInAdPlatformConversionAdapter($platform, new StandInCallLogger(new NullLogger()));

        $this->assertSame($real->platform(), $standIn->platform());
        $this->assertSame($real->supports($type), $standIn->supports($type));
        $this->assertSame($real->extractClickId($attribution), $standIn->extractClickId($attribution));
    }

    private function realAdapter(AdPlatform $platform): AdPlatformConversionAdapterInterface
    {
        return match ($platform) {
            AdPlatform::Google => new GoogleAdsConversionAdapter($this->createStub(GoogleAdsConversionService::class)),
            AdPlatform::Bing => new BingAdsConversionAdapter($this->createStub(BingAdsConversionService::class)),
        };
    }
}
