<?php

declare(strict_types=1);

namespace App\Application\Contracts;

use App\Domain\Catalog\Product\ValueObjects\SkuPriceChange;
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
     * @param list<SkuPriceChange> $priceChanges Confirmed price changes per SKU
     *
     * @throws InvalidConfigurationException When target channel is not configured
     * @throws ExternalServiceUnavailableException On delivery failure
     */
    public function sendPriceUpdateAlert(
        IntId $productId,
        array $priceChanges,
        ?string $productTitle = null,
        ?string $productUrl = null,
    ): void;

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
     * @param list<string> $createdVariants Created variant labels
     *
     * @throws InvalidConfigurationException When target channel is not configured
     * @throws ExternalServiceUnavailableException On delivery failure
     */
    public function sendVariantSkusGenerated(
        int $productId,
        string $productTitle,
        int $created,
        int $skipped,
        int $failed,
        array $createdVariants,
    ): void;
}
