<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Conversion\CallTracking\ValueObjects;

use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\Conversion\CallTracking\ValueObjects\CallTrackingVisit;
use App\Domain\Conversion\CallTracking\ValueObjects\PhoneNumberE164;
use App\Domain\ValueObjects\Guid;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CallTrackingVisit::class)]
final class CallTrackingVisitTest extends TestCase
{
    #[Test]
    public function it_constructs_with_the_required_fields(): void
    {
        $attribution = MarketingAttribution::empty();
        $trackingNumber = PhoneNumberE164::from('+441234567890');

        $visit = new CallTrackingVisit(
            attribution: $attribution,
            marketingConsentGranted: true,
            trackingNumberShown: $trackingNumber,
            ipAddress: '203.0.113.42',
        );

        $this->assertSame($attribution, $visit->attribution);
        $this->assertTrue($visit->marketingConsentGranted);
        $this->assertSame($trackingNumber, $visit->trackingNumberShown);
        $this->assertSame('203.0.113.42', $visit->ipAddress);
        $this->assertNull($visit->userAgent);
        $this->assertNull($visit->refererUrl);
        $this->assertNull($visit->id);
        $this->assertNull($visit->createdAt);
    }

    #[Test]
    public function it_preserves_the_attribution_payload(): void
    {
        $attribution = new MarketingAttribution(
            gclid: 'CNHz5eD_8pkCFRCdnAodzniYQg',
            utmSource: 'google',
            utmMedium: 'cpc',
            utmCampaign: 'spring_sale',
        );

        $visit = new CallTrackingVisit(
            attribution: $attribution,
            marketingConsentGranted: true,
            trackingNumberShown: PhoneNumberE164::from('+441234567890'),
            ipAddress: '203.0.113.42',
        );

        $this->assertSame('CNHz5eD_8pkCFRCdnAodzniYQg', $visit->attribution->gclid);
        $this->assertSame('google', $visit->attribution->utmSource);
        $this->assertTrue($visit->attribution->hasAnyAttribution());
    }

    #[Test]
    public function it_carries_optional_request_context_when_provided(): void
    {
        $visit = new CallTrackingVisit(
            attribution: MarketingAttribution::empty(),
            marketingConsentGranted: false,
            trackingNumberShown: PhoneNumberE164::from('+441234567890'),
            ipAddress: '203.0.113.42',
            userAgent: 'Mozilla/5.0',
            refererUrl: 'https://example.com/landing',
        );

        $this->assertFalse($visit->marketingConsentGranted);
        $this->assertSame('Mozilla/5.0', $visit->userAgent);
        $this->assertSame('https://example.com/landing', $visit->refererUrl);
    }

    #[Test]
    public function it_rejects_an_empty_ip_address(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CallTrackingVisit(
            attribution: MarketingAttribution::empty(),
            marketingConsentGranted: true,
            trackingNumberShown: PhoneNumberE164::from('+441234567890'),
            ipAddress: '',
        );
    }

    #[Test]
    public function it_carries_id_and_created_at_when_hydrated(): void
    {
        $id = Guid::fromTrusted('11111111-2222-3333-4444-555555555555');
        $createdAt = new DateTimeImmutable('2026-05-26T10:00:00+00:00');

        $visit = new CallTrackingVisit(
            attribution: MarketingAttribution::empty(),
            marketingConsentGranted: true,
            trackingNumberShown: PhoneNumberE164::from('+441234567890'),
            ipAddress: '203.0.113.42',
            id: $id,
            createdAt: $createdAt,
        );

        $this->assertSame($id, $visit->id);
        $this->assertSame($createdAt, $visit->createdAt);
    }
}
