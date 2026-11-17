<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\StandIns;

use App\Application\Contracts\ChatNotificationInterface;
use App\Application\Notifications\DTOs\AlertNotificationDataDTO;
use App\Application\Notifications\DTOs\PriceUpdateAlertDataDTO;
use App\Application\Notifications\DTOs\VariantSkuNotificationDataDTO;
use App\Application\Notifications\Enums\AlertAudience;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmission;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Integrations\StandInCallLogger;

final readonly class StandInChatNotification implements ChatNotificationInterface
{
    public function __construct(private StandInCallLogger $logger) {}

    public function sendAlert(AlertAudience $audience, AlertNotificationDataDTO $data): void
    {
        $this->logger->record(StandInCallLogger::SLACK, 'sendAlert', ['audience' => $audience->name]);
    }

    public function sendPriceUpdateAlert(PriceUpdateAlertDataDTO $data): void
    {
        $this->logger->record(StandInCallLogger::SLACK, 'sendPriceUpdateAlert');
    }

    public function sendContactFormProcessed(IntId $conversationId, string $customerName, string $customerEmail): void
    {
        $this->logger->record(StandInCallLogger::SLACK, 'sendContactFormProcessed', [
            'conversation_id' => $conversationId->value,
        ]);
    }

    public function sendContactFormFailed(
        ContactSubmission $submission,
        string $submissionId,
        string $errorMessage,
        ?bool $emailValid,
    ): void {
        $this->logger->record(StandInCallLogger::SLACK, 'sendContactFormFailed', [
            'submission_id' => $submissionId,
        ]);
    }

    public function sendVariantSkusGenerated(VariantSkuNotificationDataDTO $data): void
    {
        $this->logger->record(StandInCallLogger::SLACK, 'sendVariantSkusGenerated');
    }
}
