<?php

declare(strict_types=1);

namespace App\Infrastructure\Conversion\CallTracking\Models;

use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\Conversion\CallTracking\ValueObjects\CallTrackingVisit;
use App\Domain\Conversion\CallTracking\ValueObjects\PhoneNumberE164;
use App\Domain\Exceptions\Data\InvalidFormatException;
use App\Domain\ValueObjects\IpAddress;
use App\Domain\ValueObjects\Uuid;
use App\Infrastructure\Contracts\EloquentDomainMappableInterface;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $id
 * @property string|null $gclid
 * @property string|null $gclsrc
 * @property string|null $wbraid
 * @property string|null $gbraid
 * @property string|null $msclkid
 * @property string|null $fbclid
 * @property string|null $utm_source
 * @property string|null $utm_medium
 * @property string|null $utm_campaign
 * @property string|null $utm_content
 * @property string|null $utm_term
 * @property bool $marketing_consent_granted
 * @property string $tracking_number_shown
 * @property string $ip_address
 * @property string|null $user_agent
 * @property CarbonImmutable $created_at
 *
 * @implements EloquentDomainMappableInterface<CallTrackingVisit>
 */
final class CallTrackingVisitModel extends Model implements EloquentDomainMappableInterface
{
    use HasUuids;

    /**
     * `user_agent` is captured server-side from a public request header. Cap to
     * keep one malicious client from inflating row size — analytics doesn't
     * need anything past the standard product/version string.
     */
    private const int USER_AGENT_MAX_LENGTH = 1024;

    protected $table = 'customer_service.call_tracking_visits';

    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'marketing_consent_granted' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param CallTrackingVisit $entity
     *
     * @return array<string, mixed>
     */
    #[Override]
    public static function fromDomainAttributes(object $entity): array
    {
        return [
            ...self::attributionAttributes($entity->attribution),
            'marketing_consent_granted' => $entity->marketingConsentGranted,
            'tracking_number_shown' => $entity->trackingNumberShown->value,
            'ip_address' => $entity->ipAddress->value,
            'user_agent' => $entity->userAgent === null ? null : \mb_substr($entity->userAgent, 0, self::USER_AGENT_MAX_LENGTH),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private static function attributionAttributes(MarketingAttribution $attribution): array
    {
        return [
            'gclid' => $attribution->gclid,
            'gclsrc' => $attribution->gclsrc,
            'wbraid' => $attribution->wbraid,
            'gbraid' => $attribution->gbraid,
            'msclkid' => $attribution->msclkid,
            'fbclid' => $attribution->fbclid,
            'utm_source' => $attribution->utmSource,
            'utm_medium' => $attribution->utmMedium,
            'utm_campaign' => $attribution->utmCampaign,
            'utm_content' => $attribution->utmContent,
            'utm_term' => $attribution->utmTerm,
        ];
    }

    /** @throws InvalidFormatException If a stored value bypasses the E.164/IP guards */
    public function toDomain(): CallTrackingVisit
    {
        return new CallTrackingVisit(
            attribution: new MarketingAttribution(
                gclid: $this->gclid,
                gclsrc: $this->gclsrc,
                wbraid: $this->wbraid,
                gbraid: $this->gbraid,
                msclkid: $this->msclkid,
                fbclid: $this->fbclid,
                utmSource: $this->utm_source,
                utmMedium: $this->utm_medium,
                utmCampaign: $this->utm_campaign,
                utmContent: $this->utm_content,
                utmTerm: $this->utm_term,
            ),
            marketingConsentGranted: $this->marketing_consent_granted,
            trackingNumberShown: PhoneNumberE164::from($this->tracking_number_shown),
            ipAddress: IpAddress::from($this->ip_address),
            userAgent: $this->user_agent,
            id: Uuid::fromTrusted($this->id),
            createdAt: $this->created_at->toDateTimeImmutable(),
        );
    }
}
