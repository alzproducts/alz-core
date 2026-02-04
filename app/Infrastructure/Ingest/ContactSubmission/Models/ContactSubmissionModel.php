<?php

declare(strict_types=1);

namespace App\Infrastructure\Ingest\ContactSubmission\Models;

use App\Domain\ContactSubmission\Enums\ContactReason;
use App\Domain\Customer\Enums\CustomerType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for public_ingest.contact_submissions table.
 *
 * Immutable snapshot of contact form submissions. No updates allowed -
 * only insert and read operations. Processing state is tracked separately
 * in customer_service.contact_submission_actions.
 *
 * @property string $id UUID primary key
 * @property string $name
 * @property string $email
 * @property ContactReason $reason
 * @property string $message
 * @property string|null $phone
 * @property CustomerType|null $customer_type
 * @property string|null $order_number
 * @property string|null $delivery_postcode
 * @property int|null $quantity
 * @property array<string, mixed>|null $product JSONB product context
 * @property string|null $shopwired_customer_id
 * @property bool $consent_marketing
 * @property bool $consent_statistics
 * @property bool $consent_preferences
 * @property bool $consent_has_responded
 * @property string|null $gclid
 * @property string|null $utm_source
 * @property string|null $utm_medium
 * @property string|null $utm_campaign
 * @property string|null $utm_content
 * @property string|null $utm_term
 * @property string|null $page_url
 * @property string|null $referrer_url
 * @property string|null $user_agent
 * @property CarbonImmutable $client_timestamp
 * @property string $ip_address
 * @property CarbonImmutable $created_at
 */
final class ContactSubmissionModel extends Model
{
    use HasUuids;

    protected $table = 'public_ingest.contact_submissions';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Immutable records - no updated_at column.
     */
    public const UPDATED_AT = null;

    /**
     * No mass assignment protection needed - all writes are server-controlled
     * via repository with explicit property assignment.
     */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => ContactReason::class,
            'customer_type' => CustomerType::class,
            'product' => 'array',
            'consent_marketing' => 'boolean',
            'consent_statistics' => 'boolean',
            'consent_preferences' => 'boolean',
            'consent_has_responded' => 'boolean',
            'quantity' => 'integer',
            'client_timestamp' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
