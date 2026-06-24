<?php

declare(strict_types=1);

namespace App\Application\Contracts;

use App\Application\Notifications\DTOs\AlertNotificationDataDTO;
use App\Application\Notifications\DTOs\PriceUpdateAlertDataDTO;
use App\Application\Notifications\DTOs\VariantSkuNotificationDataDTO;
use App\Application\Notifications\Enums\AlertAudience;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmission;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Domain\ValueObjects\IntId;

/**
 * Sends chat notifications with domain-level data.
 *
 * Each method maps to a distinct notification type. The implementation
 * handles all delivery mechanics (message formatting, channel routing,
 * config resolution).
 */
interface ChatNotificationInterface
{
    /**
     * Send an alert to the channel for the given audience.
     *
     * @throws InvalidConfigurationException When target channel is not configured
     * @throws ExternalServiceUnavailableException On delivery failure
     */
    public function sendAlert(AlertAudience $audience, AlertNotificationDataDTO $data): void;

    /**
     * @throws InvalidConfigurationException When target channel is not configured
     * @throws ExternalServiceUnavailableException On delivery failure
     */
    public function sendPriceUpdateAlert(PriceUpdateAlertDataDTO $data): void;

    /**
     * @throws InvalidConfigurationException When target channel is not configured
     * @throws ExternalServiceUnavailableException On delivery failure
     */
    public function sendContactFormProcessed(
        IntId $conversationId,
        string $customerName,
        string $customerEmail,
    ): void;

    /**
     * @throws InvalidConfigurationException When target channel is not configured
     * @throws ExternalServiceUnavailableException On delivery failure
     */
    public function sendContactFormFailed(
        ContactSubmission $submission,
        string $submissionId,
        string $errorMessage,
        ?bool $emailValid,
    ): void;

    /**
     * @throws InvalidConfigurationException When target channel is not configured
     * @throws ExternalServiceUnavailableException On delivery failure
     */
    public function sendVariantSkusGenerated(VariantSkuNotificationDataDTO $data): void;
}
