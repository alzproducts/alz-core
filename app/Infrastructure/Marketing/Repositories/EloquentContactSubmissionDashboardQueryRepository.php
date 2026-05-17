<?php

declare(strict_types=1);

namespace App\Infrastructure\Marketing\Repositories;

use App\Application\ContactSubmission\Queries\ContactSubmissionListQueryParams;
use App\Application\Contracts\ContactSubmission\ContactSubmissionDashboardQueryRepositoryInterface;
use App\Domain\ContactSubmission\Enums\ActionStatus;
use App\Domain\ContactSubmission\Enums\ActionType;
use App\Domain\ContactSubmission\Enums\ContactSubmissionFilterField;
use App\Domain\ContactSubmission\Enums\ConversionStatus;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmissionListItem;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\PaginatedList;
use App\Infrastructure\Ingest\ContactSubmission\Models\ContactSubmissionActionModel;
use App\Infrastructure\Ingest\ContactSubmission\Models\ContactSubmissionModel;
use App\Infrastructure\Persistence\EloquentGateway;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Override;

/**
 * Read-projection for the staff contact-submissions dashboard.
 *
 * Joins three schemas in a single round-trip:
 *  - `public_ingest.contact_submissions` (primary)                 → row identity + submission fields
 *  - `marketing.contact_submission_annotations` (LEFT JOIN, 1:1)   → annotation fields
 *  - `customer_service.contact_submission_actions` (correlated subqueries, 1:N) → latest lead/quote status + HelpScout id
 *
 * Correlated subqueries avoid the row multiplication that would result from LEFT JOINing the 1:N
 * action table directly. Annotation columns are aliased to `annot_*` so they don't collide with
 * any same-named columns on the submission model when Eloquent hydrates the row.
 */
final readonly class EloquentContactSubmissionDashboardQueryRepository implements ContactSubmissionDashboardQueryRepositoryInterface
{
    private const string SUBMISSIONS_TABLE = 'public_ingest.contact_submissions';
    private const string ANNOTATIONS_TABLE = 'marketing.contact_submission_annotations';
    private const string ACTIONS_TABLE = 'customer_service.contact_submission_actions';
    private const string ANNOTATIONS_ALIAS = 'annot';

    public function __construct(
        private EloquentGateway $eloquentGateway,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function paginate(ContactSubmissionListQueryParams $query): PaginatedList
    {
        $filters = $query->filters;

        return $this->eloquentGateway->paginate(
            modelClass: ContactSubmissionModel::class,
            scope: static function (Builder $q) use ($filters): void {
                self::applyProjection($q);
                self::applyFilters($q, $filters);
                $q->orderByDesc(self::SUBMISSIONS_TABLE . '.created_at');
            },
            relations: [],
            mapper: self::fromModel(...),
            perPage: $query->pagination->perPage,
            page: $query->pagination->page,
        );
    }

    /**
     * @param Builder<covariant Model> $q
     */
    private static function applyProjection(Builder $q): void
    {
        $alias = self::ANNOTATIONS_ALIAS;

        $q->select(self::SUBMISSIONS_TABLE . '.*')
            ->selectSub(self::actionFieldSubquery('status', ActionType::LeadReceived), 'lead_status')
            ->selectSub(self::actionFieldSubquery('status', ActionType::QuoteIssued), 'quote_status')
            ->selectSub(self::actionFieldSubquery('external_id', ActionType::HelpScout), 'helpscout_external_id')
            ->leftJoin(
                self::ANNOTATIONS_TABLE . ' as ' . $alias,
                $alias . '.contact_submission_id',
                '=',
                self::SUBMISSIONS_TABLE . '.id',
            )
            ->addSelect([
                $alias . '.is_potential_quote as annot_is_potential_quote',
                $alias . '.notes as annot_notes',
                $alias . '.quoted_at as annot_quoted_at',
            ]);
    }

    /**
     * Build a correlated subquery selecting one column from `contact_submission_actions`
     * for a given action_type, scoped to the parent submission.
     *
     * Orders by `created_at DESC` so the latest action wins when a submission accumulates
     * multiple rows of the same type (e.g. a retry that progresses from Pending to Completed).
     *
     * @return Builder<ContactSubmissionActionModel>
     */
    private static function actionFieldSubquery(string $column, ActionType $actionType): Builder
    {
        /** @var Builder<ContactSubmissionActionModel> $builder */
        $builder = ContactSubmissionActionModel::query()
            ->select($column)
            ->whereColumn('contact_submission_id', self::SUBMISSIONS_TABLE . '.id')
            ->where('action_type', $actionType->value)
            ->orderByDesc('created_at')
            ->limit(1);

        return $builder;
    }

    /**
     * @param Builder<covariant Model> $q
     * @param array<value-of<ContactSubmissionFilterField>, mixed> $filters
     */
    private static function applyFilters(Builder $q, array $filters): void
    {
        self::applyBooleanFilters($q, $filters);
        self::applyDateFilters($q, $filters);

        $conversionStatus = $filters[ContactSubmissionFilterField::ConversionStatus->value] ?? null;
        if ($conversionStatus instanceof ConversionStatus) {
            self::applyConversionStatusFilter($q, $conversionStatus);
        }
    }

    /**
     * @param Builder<covariant Model> $q
     * @param array<value-of<ContactSubmissionFilterField>, mixed> $filters
     */
    private static function applyBooleanFilters(Builder $q, array $filters): void
    {
        $hasGclid = $filters[ContactSubmissionFilterField::HasGclid->value] ?? null;
        if (\is_bool($hasGclid)) {
            $hasGclid
                ? $q->whereNotNull(self::SUBMISSIONS_TABLE . '.gclid')
                : $q->whereNull(self::SUBMISSIONS_TABLE . '.gclid');
        }

        $isPotentialQuote = $filters[ContactSubmissionFilterField::IsPotentialQuote->value] ?? null;
        if (\is_bool($isPotentialQuote)) {
            $alias = self::ANNOTATIONS_ALIAS;
            if ($isPotentialQuote) {
                $q->where($alias . '.is_potential_quote', true);
            } else {
                $q->where(static function (Builder $w) use ($alias): void {
                    $w->where($alias . '.is_potential_quote', false)
                        ->orWhereNull($alias . '.contact_submission_id');
                });
            }
        }
    }

    /**
     * @param Builder<covariant Model> $q
     * @param array<value-of<ContactSubmissionFilterField>, mixed> $filters
     */
    private static function applyDateFilters(Builder $q, array $filters): void
    {
        $dateFrom = $filters[ContactSubmissionFilterField::DateFrom->value] ?? null;
        if ($dateFrom instanceof DateTimeImmutable) {
            $q->where(self::SUBMISSIONS_TABLE . '.created_at', '>=', $dateFrom);
        }

        $dateTo = $filters[ContactSubmissionFilterField::DateTo->value] ?? null;
        if ($dateTo instanceof DateTimeImmutable) {
            $q->where(self::SUBMISSIONS_TABLE . '.created_at', '<', $dateTo);
        }
    }

    /**
     * @param Builder<covariant Model> $q
     */
    private static function applyConversionStatusFilter(Builder $q, ConversionStatus $status): void
    {
        if ($status === ConversionStatus::None) {
            $q->whereNotExists(static function (QueryBuilder $sub): void {
                $sub->from(self::ACTIONS_TABLE)
                    ->whereColumn('contact_submission_id', self::SUBMISSIONS_TABLE . '.id')
                    ->whereIn('action_type', [
                        ActionType::LeadReceived->value,
                        ActionType::QuoteIssued->value,
                    ]);
            });

            return;
        }

        [$actionType, $statuses] = match ($status) {
            ConversionStatus::LeadPending => [ActionType::LeadReceived, [ActionStatus::Pending->value, ActionStatus::Processing->value]],
            ConversionStatus::LeadSent => [ActionType::LeadReceived, [ActionStatus::Completed->value]],
            ConversionStatus::QuotePending => [ActionType::QuoteIssued, [ActionStatus::Pending->value, ActionStatus::Processing->value]],
            ConversionStatus::QuoteSent => [ActionType::QuoteIssued, [ActionStatus::Completed->value]],
        };

        $q->whereExists(static function (QueryBuilder $sub) use ($actionType, $statuses): void {
            $sub->from(self::ACTIONS_TABLE)
                ->whereColumn('contact_submission_id', self::SUBMISSIONS_TABLE . '.id')
                ->where('action_type', $actionType->value)
                ->whereIn('status', $statuses);
        });
    }

    private static function fromModel(ContactSubmissionModel $model): ContactSubmissionListItem
    {
        $leadStatusRaw = $model->getAttribute('lead_status');
        $quoteStatusRaw = $model->getAttribute('quote_status');
        $helpscoutId = $model->getAttribute('helpscout_external_id');
        $annotIsPotentialQuote = $model->getAttribute('annot_is_potential_quote');
        $annotNotes = $model->getAttribute('annot_notes');
        $annotQuotedAtRaw = $model->getAttribute('annot_quoted_at');

        return new ContactSubmissionListItem(
            id: Guid::fromTrusted($model->id),
            name: $model->name,
            email: $model->email,
            reason: $model->reason,
            customerType: $model->customer_type,
            orderNumber: $model->order_number,
            quantity: $model->quantity,
            product: $model->product,
            shopwiredCustomerId: $model->shopwired_customer_id,
            gclid: $model->gclid,
            msclkid: $model->msclkid,
            fbclid: $model->fbclid,
            utmSource: $model->utm_source,
            utmMedium: $model->utm_medium,
            utmCampaign: $model->utm_campaign,
            pageUrl: $model->page_url,
            createdAt: $model->created_at->toDateTimeImmutable(),
            helpscoutExternalId: \is_string($helpscoutId) ? $helpscoutId : null,
            leadStatus: \is_string($leadStatusRaw) ? ActionStatus::tryFrom($leadStatusRaw) : null,
            quoteStatus: \is_string($quoteStatusRaw) ? ActionStatus::tryFrom($quoteStatusRaw) : null,
            isPotentialQuote: $annotIsPotentialQuote === null ? null : (bool) $annotIsPotentialQuote,
            notes: \is_string($annotNotes) ? $annotNotes : null,
            quotedAt: \is_string($annotQuotedAtRaw)
                ? CarbonImmutable::parse($annotQuotedAtRaw)->toDateTimeImmutable()
                : null,
        );
    }
}
