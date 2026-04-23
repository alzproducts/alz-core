<?php

declare(strict_types=1);

namespace App\Infrastructure\Ingest\ContactSubmission\Models;

use App\Domain\ContactSubmission\Enums\ActionStatus;
use App\Domain\ContactSubmission\Enums\ActionType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Eloquent model for customer_service.contact_submission_actions table.
 *
 * Mutable processing state for contact submissions. Each action tracks:
 * - Processing status (pending → processing → completed|failed)
 * - External references (e.g., HelpScout conversation ID)
 * - Retry attempts and error messages
 *
 * @property string $id UUID primary key
 * @property string $contact_submission_id FK to contact_submissions
 * @property ActionType $action_type
 * @property ActionStatus $status
 * @property string|null $external_id External system reference
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

    /**
     * No mass assignment protection needed - all writes are server-controlled
     * via repository with explicit property assignment.
     */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'action_type' => ActionType::class,
            'status' => ActionStatus::class,
            'attempts' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'processing_started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
