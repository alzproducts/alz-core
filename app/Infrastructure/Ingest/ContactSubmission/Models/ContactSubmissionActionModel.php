<?php

declare(strict_types=1);

namespace App\Infrastructure\Ingest\ContactSubmission\Models;

use App\Application\Conversion\Enums\AdPlatform;
use App\Domain\ContactSubmission\Enums\ActionStatus;
use App\Domain\ContactSubmission\Enums\ActionType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $id
 * @property string $contact_submission_id
 * @property ActionType $action_type
 * @property AdPlatform|null $ad_platform NULL for HelpScout rows
 * @property ActionStatus $status
 * @property string|null $external_id
 * @property string|null $error_message
 * @property int $attempts
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property CarbonImmutable|null $processing_started_at
 * @property CarbonImmutable|null $completed_at
 */
final class ContactSubmissionActionModel extends Model
{
    use HasUuids;

    protected $table = 'customer_service.contact_submission_actions';

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
            'action_type' => ActionType::class,
            'ad_platform' => AdPlatform::class,
            'status' => ActionStatus::class,
            'attempts' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'processing_started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
