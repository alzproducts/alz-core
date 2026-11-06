<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Fakes;

use App\Application\Contracts\ChatNotificationInterface;
use App\Application\Notifications\DTOs\AlertNotificationDataDTO;
use App\Application\Notifications\DTOs\PriceUpdateAlertDataDTO;
use App\Application\Notifications\DTOs\VariantSkuNotificationDataDTO;
use App\Application\Notifications\Enums\AlertAudience;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmission;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Integrations\LiveIntegration;
use App\Infrastructure\Integrations\OutboundCallRecorder;

final readonly class RecordingChatNotification implements ChatNotificationInterface
{
    public function __construct(private OutboundCallRecorder $recorder) {}

    public function sendAlert(AlertAudience $audience, AlertNotificationDataDTO $data): void
    {
        $this->recorder->record(LiveIntegration::Slack, 'sendAlert', ['audience' => $audience->value]);
    }

    public function sendPriceUpdateAlert(PriceUpdateAlertDataDTO $data): void
    {
        $this->recorder->record(LiveIntegration::Slack, 'sendPriceUpdateAlert');
    }

    public function sendContactFormProcessed(IntId $conversationId, string $customerName, string $customerEmail): void
    {
        $this->recorder->record(LiveIntegration::Slack, 'sendContactFormProcessed', [
            'conversation_id' => $conversationId->value,
        ]);
    }

    public function sendContactFormFailed(
        ContactSubmission $submission,
        string $submissionId,
        string $errorMessage,
        ?bool $emailValid,
    ): void {
        $this->recorder->record(LiveIntegration::Slack, 'sendContactFormFailed', [
            'submission_id' => $submissionId,
        ]);
    }

    public function sendVariantSkusGenerated(VariantSkuNotificationDataDTO $data): void
    {
        $this->recorder->record(LiveIntegration::Slack, 'sendVariantSkusGenerated');
    }
}
