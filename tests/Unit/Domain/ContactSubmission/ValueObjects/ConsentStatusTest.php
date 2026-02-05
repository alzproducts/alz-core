<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ContactSubmission\ValueObjects;

use App\Domain\ContactSubmission\ValueObjects\ConsentStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ConsentStatus Value Object Unit Tests.
 *
 * Tests the Consent Mode v2 snapshot captured at form submission.
 * hasAnyConsent() is critical for determining user consent state.
 */
#[CoversClass(ConsentStatus::class)]
final class ConsentStatusTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Construction Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_with_all_consents(): void
    {
        $consent = new ConsentStatus(
            marketing: true,
            statistics: true,
            preferences: true,
            hasResponded: true,
        );

        self::assertTrue($consent->marketing);
        self::assertTrue($consent->statistics);
        self::assertTrue($consent->preferences);
        self::assertTrue($consent->hasResponded);
    }

    #[Test]
    public function it_creates_with_no_consents(): void
    {
        $consent = new ConsentStatus(
            marketing: false,
            statistics: false,
            preferences: false,
            hasResponded: true,
        );

        self::assertFalse($consent->marketing);
        self::assertFalse($consent->statistics);
        self::assertFalse($consent->preferences);
        self::assertTrue($consent->hasResponded);
    }

    /*
    |--------------------------------------------------------------------------
    | denied() Factory Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function denied_factory_creates_with_all_false(): void
    {
        $consent = ConsentStatus::denied();

        self::assertFalse($consent->marketing);
        self::assertFalse($consent->statistics);
        self::assertFalse($consent->preferences);
        self::assertFalse($consent->hasResponded);
    }

    /*
    |--------------------------------------------------------------------------
    | hasAnyConsent() Tests - CRITICAL BUSINESS LOGIC
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function hasAnyConsent_returns_false_when_all_false(): void
    {
        $consent = new ConsentStatus(
            marketing: false,
            statistics: false,
            preferences: false,
            hasResponded: true,
        );

        self::assertFalse($consent->hasAnyConsent());
    }

    #[Test]
    public function hasAnyConsent_returns_true_when_marketing_true(): void
    {
        $consent = new ConsentStatus(
            marketing: true,
            statistics: false,
            preferences: false,
            hasResponded: true,
        );

        self::assertTrue($consent->hasAnyConsent());
    }

    #[Test]
    public function hasAnyConsent_returns_true_when_statistics_true(): void
    {
        $consent = new ConsentStatus(
            marketing: false,
            statistics: true,
            preferences: false,
            hasResponded: true,
        );

        self::assertTrue($consent->hasAnyConsent());
    }

    #[Test]
    public function hasAnyConsent_returns_true_when_preferences_true(): void
    {
        $consent = new ConsentStatus(
            marketing: false,
            statistics: false,
            preferences: true,
            hasResponded: true,
        );

        self::assertTrue($consent->hasAnyConsent());
    }

    #[Test]
    public function hasAnyConsent_returns_true_when_all_true(): void
    {
        $consent = new ConsentStatus(
            marketing: true,
            statistics: true,
            preferences: true,
            hasResponded: true,
        );

        self::assertTrue($consent->hasAnyConsent());
    }

    #[Test]
    public function hasAnyConsent_ignores_hasResponded_flag(): void
    {
        // hasResponded is NOT a consent type - it just indicates user interaction
        $consentNotResponded = new ConsentStatus(
            marketing: false,
            statistics: false,
            preferences: false,
            hasResponded: false,
        );

        $consentResponded = new ConsentStatus(
            marketing: false,
            statistics: false,
            preferences: false,
            hasResponded: true,
        );

        // Both should return false regardless of hasResponded
        self::assertFalse($consentNotResponded->hasAnyConsent());
        self::assertFalse($consentResponded->hasAnyConsent());
    }
}
