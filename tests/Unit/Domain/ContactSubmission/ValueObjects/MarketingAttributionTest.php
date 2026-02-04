<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ContactSubmission\ValueObjects;

use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * MarketingAttribution Value Object Unit Tests.
 *
 * Tests the marketing attribution parameters (GCLID, UTM) captured at submission.
 * hasAnyAttribution() determines if conversion tracking data is present.
 */
#[CoversClass(MarketingAttribution::class)]
final class MarketingAttributionTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Construction Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_with_all_fields(): void
    {
        $attribution = new MarketingAttribution(
            gclid: 'CjwKCAjw',
            utmSource: 'google',
            utmMedium: 'cpc',
            utmCampaign: 'spring_sale',
            utmContent: 'banner_ad',
            utmTerm: 'mobility aids',
        );

        self::assertSame('CjwKCAjw', $attribution->gclid);
        self::assertSame('google', $attribution->utmSource);
        self::assertSame('cpc', $attribution->utmMedium);
        self::assertSame('spring_sale', $attribution->utmCampaign);
        self::assertSame('banner_ad', $attribution->utmContent);
        self::assertSame('mobility aids', $attribution->utmTerm);
    }

    #[Test]
    public function it_creates_with_defaults(): void
    {
        $attribution = new MarketingAttribution();

        self::assertNull($attribution->gclid);
        self::assertNull($attribution->utmSource);
        self::assertNull($attribution->utmMedium);
        self::assertNull($attribution->utmCampaign);
        self::assertNull($attribution->utmContent);
        self::assertNull($attribution->utmTerm);
    }

    /*
    |--------------------------------------------------------------------------
    | empty() Factory Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function empty_factory_creates_with_all_null(): void
    {
        $attribution = MarketingAttribution::empty();

        self::assertNull($attribution->gclid);
        self::assertNull($attribution->utmSource);
        self::assertNull($attribution->utmMedium);
        self::assertNull($attribution->utmCampaign);
        self::assertNull($attribution->utmContent);
        self::assertNull($attribution->utmTerm);
    }

    /*
    |--------------------------------------------------------------------------
    | hasAnyAttribution() Tests - CRITICAL FOR CONVERSION TRACKING
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function hasAnyAttribution_returns_false_when_all_null(): void
    {
        $attribution = MarketingAttribution::empty();

        self::assertFalse($attribution->hasAnyAttribution());
    }

    #[Test]
    public function hasAnyAttribution_returns_true_when_gclid_present(): void
    {
        $attribution = new MarketingAttribution(gclid: 'CjwKCAjw');

        self::assertTrue($attribution->hasAnyAttribution());
    }

    #[Test]
    public function hasAnyAttribution_returns_true_when_utmSource_present(): void
    {
        $attribution = new MarketingAttribution(utmSource: 'google');

        self::assertTrue($attribution->hasAnyAttribution());
    }

    #[Test]
    public function hasAnyAttribution_returns_true_when_utmMedium_present(): void
    {
        $attribution = new MarketingAttribution(utmMedium: 'cpc');

        self::assertTrue($attribution->hasAnyAttribution());
    }

    #[Test]
    public function hasAnyAttribution_returns_true_when_utmCampaign_present(): void
    {
        $attribution = new MarketingAttribution(utmCampaign: 'spring_sale');

        self::assertTrue($attribution->hasAnyAttribution());
    }

    #[Test]
    public function hasAnyAttribution_returns_true_when_utmContent_present(): void
    {
        $attribution = new MarketingAttribution(utmContent: 'banner_ad');

        self::assertTrue($attribution->hasAnyAttribution());
    }

    #[Test]
    public function hasAnyAttribution_returns_true_when_utmTerm_present(): void
    {
        $attribution = new MarketingAttribution(utmTerm: 'mobility aids');

        self::assertTrue($attribution->hasAnyAttribution());
    }

    #[Test]
    public function hasAnyAttribution_returns_true_when_all_fields_present(): void
    {
        $attribution = new MarketingAttribution(
            gclid: 'CjwKCAjw',
            utmSource: 'google',
            utmMedium: 'cpc',
            utmCampaign: 'spring_sale',
            utmContent: 'banner_ad',
            utmTerm: 'mobility aids',
        );

        self::assertTrue($attribution->hasAnyAttribution());
    }
}
