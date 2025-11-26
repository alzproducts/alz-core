<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Support;

use App\Application\Support\CacheTimesTrait;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * CacheTimesTrait Unit Tests.
 *
 * Tests the cache TTL constants ensuring:
 * - Correct values in seconds
 * - Mathematical consistency between related constants
 */
#[CoversTrait(CacheTimesTrait::class)]
final class CacheTimesTraitTest extends TestCase
{
    /**
     * Anonymous class to expose the trait's protected constants.
     */
    private object $traitUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->traitUser = new class {
            use CacheTimesTrait;

            public function getConstant(string $name): int
            {
                return \constant("self::{$name}");
            }
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Correct Value Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function one_minute_equals_60_seconds(): void
    {
        self::assertSame(60, $this->traitUser->getConstant('ONE_MINUTE'));
    }

    #[Test]
    public function five_minutes_equals_300_seconds(): void
    {
        self::assertSame(300, $this->traitUser->getConstant('FIVE_MINUTES'));
    }

    #[Test]
    public function one_hour_equals_3600_seconds(): void
    {
        self::assertSame(3600, $this->traitUser->getConstant('ONE_HOUR'));
    }

    #[Test]
    public function one_day_equals_86400_seconds(): void
    {
        self::assertSame(86400, $this->traitUser->getConstant('ONE_DAY'));
    }

    #[Test]
    public function seven_days_equals_604800_seconds(): void
    {
        self::assertSame(604800, $this->traitUser->getConstant('SEVEN_DAYS'));
    }

    #[Test]
    public function thirty_days_equals_2592000_seconds(): void
    {
        self::assertSame(2592000, $this->traitUser->getConstant('THIRTY_DAYS'));
    }

    /*
    |--------------------------------------------------------------------------
    | Mathematical Consistency Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function five_minutes_is_five_times_one_minute(): void
    {
        self::assertSame(
            5 * $this->traitUser->getConstant('ONE_MINUTE'),
            $this->traitUser->getConstant('FIVE_MINUTES'),
        );
    }

    #[Test]
    public function one_hour_is_sixty_times_one_minute(): void
    {
        self::assertSame(
            60 * $this->traitUser->getConstant('ONE_MINUTE'),
            $this->traitUser->getConstant('ONE_HOUR'),
        );
    }

    #[Test]
    public function one_day_is_twenty_four_times_one_hour(): void
    {
        self::assertSame(
            24 * $this->traitUser->getConstant('ONE_HOUR'),
            $this->traitUser->getConstant('ONE_DAY'),
        );
    }

    #[Test]
    public function seven_days_is_seven_times_one_day(): void
    {
        self::assertSame(
            7 * $this->traitUser->getConstant('ONE_DAY'),
            $this->traitUser->getConstant('SEVEN_DAYS'),
        );
    }

    #[Test]
    public function thirty_days_is_thirty_times_one_day(): void
    {
        self::assertSame(
            30 * $this->traitUser->getConstant('ONE_DAY'),
            $this->traitUser->getConstant('THIRTY_DAYS'),
        );
    }
}
