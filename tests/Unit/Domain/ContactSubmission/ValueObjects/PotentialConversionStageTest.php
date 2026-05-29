<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ContactSubmission\ValueObjects;

use App\Domain\ContactSubmission\Enums\ActionStatus;
use App\Domain\ContactSubmission\Enums\PotentialConversionSource;
use App\Domain\ContactSubmission\ValueObjects\PotentialConversionStage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PotentialConversionStage::class)]
final class PotentialConversionStageTest extends TestCase
{
    #[Test]
    public function is_form_is_true_for_form_source(): void
    {
        $stage = new PotentialConversionStage(PotentialConversionSource::Form, null, null);

        self::assertTrue($stage->isForm());
    }

    #[Test]
    public function is_form_is_false_for_call_source(): void
    {
        $stage = new PotentialConversionStage(PotentialConversionSource::Call, null, null);

        self::assertFalse($stage->isForm());
    }

    #[Test]
    public function has_lead_action_is_true_when_lead_status_present(): void
    {
        $stage = new PotentialConversionStage(PotentialConversionSource::Form, ActionStatus::Completed, null);

        self::assertTrue($stage->hasLeadAction());
    }

    #[Test]
    public function has_lead_action_is_false_when_lead_status_null(): void
    {
        $stage = new PotentialConversionStage(PotentialConversionSource::Form, null, null);

        self::assertFalse($stage->hasLeadAction());
    }
}
