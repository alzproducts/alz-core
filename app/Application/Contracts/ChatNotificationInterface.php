<?php

declare(strict_types=1);

namespace App\Application\Contracts;

use App\Application\Notifications\DTOs\PriceUpdateAlertDataDTO;
use App\Application\Notifications\DTOs\VariantSkuNotificationDataDTO;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmission;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;

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
     * @param array<string, mixed> $context Key-value pairs shown as context in the notification
     *
     * @throws InvalidConfigurationException When target channel is not configured
     * @throws ExternalServiceUnavailableException On delivery failure
     */
    public function sendAdminAlert(
        string $title,
        string $message,
        array $context,
        DateTimeImmutable $firedAt,
    ): void;

    /**
     * @param array<string, mixed> $context Key-value pairs shown as context in the notification
     *
     * @throws InvalidConfigurationException When target channel is not configured
     * @throws ExternalServiceUnavailableException On delivery failure
     */
    public function sendManagerAlert(
        string $title,
        string $message,
        array $context,
        DateTimeImmutable $firedAt,
    ): void;

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
