<?php

declare(strict_types=1);

namespace App\Infrastructure\Marketing\Models;

use App\Domain\ContactSubmission\Enums\ActionStatus;
use App\Domain\ContactSubmission\Enums\ContactReason;
use App\Domain\Customer\Enums\CustomerType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Read-only Eloquent model for marketing.contact_submission_dashboard_view.
 *
 * Each row is one contact submission with its annotation columns and latest
 * action statuses pre-joined. The view's underlying schema joins three tables;
 * consumers see a flat row with native-typed attributes.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property ContactReason $reason
 * @property CustomerType|null $customer_type
 * @property string|null $order_number
 * @property int|null $quantity
 * @property array<string, mixed>|null $product
 * @property string|null $shopwired_customer_id
 * @property string|null $gclid
 * @property string|null $msclkid
 * @property string|null $fbclid
 * @property string|null $utm_source
 * @property string|null $utm_medium
 * @property string|null $utm_campaign
 * @property string|null $page_url
 * @property CarbonImmutable $created_at
 * @property bool|null $is_potential_quote
 * @property string|null $notes
 * @property CarbonImmutable|null $quoted_at
 * @property ActionStatus|null $lead_status
 * @property ActionStatus|null $quote_status
 * @property string|null $helpscout_external_id
 */
final class ContactSubmissionDashboardViewModel extends Model
{
    protected $table = 'marketing.contact_submission_dashboard_view';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'reason' => ContactReason::class,
            'customer_type' => CustomerType::class,
            'product' => 'array',
            'quantity' => 'integer',
            'created_at' => 'immutable_datetime',
            'is_potential_quote' => 'boolean',
            'quoted_at' => 'immutable_datetime',
            'lead_status' => ActionStatus::class,
            'quote_status' => ActionStatus::class,
        ];
    }
}
